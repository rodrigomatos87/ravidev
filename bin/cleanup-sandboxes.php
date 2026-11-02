#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Ravi Developer - Sandbox Cleanup Script
 * 
 * This script removes old/idle Docker sandbox containers and their volumes.
 * Should be run periodically via cron.
 * 
 * Usage:
 *   php bin/cleanup-sandboxes.php
 *   php bin/cleanup-sandboxes.php --dry-run
 *   php bin/cleanup-sandboxes.php --max-age=3600
 */

// ============================================================================
// BOOTSTRAP
// ============================================================================

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

// ============================================================================
// CONSTANTS
// ============================================================================

define('ROOT_PATH', dirname(__DIR__));
define('STORAGE_PATH', ROOT_PATH . '/storage');

// ============================================================================
// CONFIGURATION
// ============================================================================

$options = getopt('', ['dry-run', 'max-age:', 'help', 'verbose']);

if (isset($options['help'])) {
    echo <<<HELP
Ravi Developer Sandbox Cleanup Script

Usage:
  php bin/cleanup-sandboxes.php [options]

Options:
  --dry-run      Show what would be deleted without actually deleting
  --max-age=SEC  Maximum age in seconds before cleanup (default: 3600)
  --verbose      Show detailed output
  --help         Show this help message

Examples:
  php bin/cleanup-sandboxes.php
  php bin/cleanup-sandboxes.php --dry-run
  php bin/cleanup-sandboxes.php --max-age=7200 --verbose

HELP;
    exit(0);
}

$dryRun = isset($options['dry-run']);
$maxAge = (int) ($options['max-age'] ?? 3600); // 1 hour default
$verbose = isset($options['verbose']);

// ============================================================================
// HELPERS
// ============================================================================

function output(string $message, bool $verboseOnly = false): void
{
    global $verbose;
    if (!$verboseOnly || $verbose) {
        echo date('[Y-m-d H:i:s]') . ' ' . $message . "\n";
    }
}

function executeCommand(string $command): array
{
    $output = [];
    $returnCode = 0;
    exec($command . ' 2>&1', $output, $returnCode);
    return [
        'output' => $output,
        'code' => $returnCode
    ];
}

// ============================================================================
// MAIN SCRIPT
// ============================================================================

output('Starting sandbox cleanup...');
output("Max age: {$maxAge} seconds", true);
output($dryRun ? 'DRY RUN MODE - No changes will be made' : 'LIVE MODE - Changes will be applied');

$cleanedCount = 0;
$errorCount = 0;

// ============================================================================
// CLEANUP DOCKER CONTAINERS
// ============================================================================

output('Checking Docker containers...', true);

// Get all Ravi sandbox containers
$result = executeCommand("docker ps -a --filter 'name=ravi-sandbox-' --format '{{.ID}}\t{{.Names}}\t{{.Status}}\t{{.CreatedAt}}'");

if ($result['code'] !== 0) {
    output('ERROR: Failed to list Docker containers');
    output('Make sure Docker is running and accessible');
    exit(1);
}

$containers = [];
foreach ($result['output'] as $line) {
    if (empty(trim($line))) continue;
    
    $parts = explode("\t", $line);
    if (count($parts) >= 4) {
        $containers[] = [
            'id' => $parts[0],
            'name' => $parts[1],
            'status' => $parts[2],
            'created' => $parts[3]
        ];
    }
}

output("Found " . count($containers) . " sandbox container(s)", true);

foreach ($containers as $container) {
    $containerName = $container['name'];
    $containerId = $container['id'];
    
    // Extract project UUID from container name (format: ravi-sandbox-{uuid})
    preg_match('/ravi-sandbox-([a-f0-9-]+)/', $containerName, $matches);
    $projectUuid = $matches[1] ?? null;
    
    // Check container age
    $createdTime = strtotime($container['created']);
    $age = time() - $createdTime;
    
    if ($age > $maxAge) {
        output("Container {$containerName} is older than max age ({$age}s > {$maxAge}s)");
        
        if (!$dryRun) {
            // Stop container
            output("  Stopping container...", true);
            $stopResult = executeCommand("docker stop {$containerId}");
            
            // Remove container
            output("  Removing container...", true);
            $rmResult = executeCommand("docker rm {$containerId}");
            
            if ($rmResult['code'] === 0) {
                output("  ✓ Container removed successfully");
                $cleanedCount++;
            } else {
                output("  ✗ Failed to remove container");
                $errorCount++;
            }
        } else {
            output("  [DRY RUN] Would remove container {$containerName}");
            $cleanedCount++;
        }
    } else {
        output("Container {$containerName} is within max age ({$age}s < {$maxAge}s)", true);
    }
}

// ============================================================================
// CLEANUP SANDBOX FILES
// ============================================================================

output('Checking sandbox files...', true);

$sandboxPath = STORAGE_PATH . '/sandbox';
if (is_dir($sandboxPath)) {
    $directories = glob($sandboxPath . '/*', GLOB_ONLYDIR);
    
    output("Found " . count($directories) . " sandbox director(ies)", true);
    
    foreach ($directories as $dir) {
        $dirName = basename($dir);
        $mtime = filemtime($dir);
        $age = time() - $mtime;
        
        if ($age > $maxAge) {
            output("Directory {$dirName} is older than max age ({$age}s > {$maxAge}s)");
            
            if (!$dryRun) {
                // Remove directory recursively
                output("  Removing directory...", true);
                $rmResult = executeCommand("rm -rf " . escapeshellarg($dir));
                
                if ($rmResult['code'] === 0) {
                    output("  ✓ Directory removed successfully");
                    $cleanedCount++;
                } else {
                    output("  ✗ Failed to remove directory");
                    $errorCount++;
                }
            } else {
                output("  [DRY RUN] Would remove directory {$dirName}");
                $cleanedCount++;
            }
        } else {
            output("Directory {$dirName} is within max age ({$age}s < {$maxAge}s)", true);
        }
    }
} else {
    output("Sandbox directory does not exist: {$sandboxPath}", true);
}

// ============================================================================
// CLEANUP DOCKER VOLUMES
// ============================================================================

output('Checking dangling Docker volumes...', true);

$result = executeCommand("docker volume ls -q --filter 'dangling=true' --filter 'name=ravi-'");

if ($result['code'] === 0 && !empty($result['output'])) {
    $volumes = array_filter($result['output'], fn($v) => !empty(trim($v)));
    
    output("Found " . count($volumes) . " dangling volume(s)", true);
    
    foreach ($volumes as $volume) {
        $volume = trim($volume);
        if (empty($volume)) continue;
        
        output("Dangling volume: {$volume}");
        
        if (!$dryRun) {
            $rmResult = executeCommand("docker volume rm " . escapeshellarg($volume));
            
            if ($rmResult['code'] === 0) {
                output("  ✓ Volume removed successfully");
                $cleanedCount++;
            } else {
                output("  ✗ Failed to remove volume");
                $errorCount++;
            }
        } else {
            output("  [DRY RUN] Would remove volume {$volume}");
            $cleanedCount++;
        }
    }
} else {
    output("No dangling volumes found", true);
}

// ============================================================================
// CLEANUP OLD LOG FILES
// ============================================================================

output('Checking old log files...', true);

$logsPath = STORAGE_PATH . '/logs';
$logMaxAge = 604800; // 7 days for logs

if (is_dir($logsPath)) {
    $logFiles = glob($logsPath . '/*.log');
    
    foreach ($logFiles as $logFile) {
        $mtime = filemtime($logFile);
        $age = time() - $mtime;
        
        if ($age > $logMaxAge) {
            $fileName = basename($logFile);
            output("Old log file: {$fileName} ({$age}s old)");
            
            if (!$dryRun) {
                if (unlink($logFile)) {
                    output("  ✓ Log file removed");
                    $cleanedCount++;
                } else {
                    output("  ✗ Failed to remove log file");
                    $errorCount++;
                }
            } else {
                output("  [DRY RUN] Would remove {$fileName}");
                $cleanedCount++;
            }
        }
    }
}

// ============================================================================
// SUMMARY
// ============================================================================

echo "\n";
output('=== Cleanup Summary ===');
output("Items cleaned: {$cleanedCount}");
output("Errors: {$errorCount}");
output($dryRun ? 'Mode: DRY RUN (no changes made)' : 'Mode: LIVE');
output('Cleanup completed.');

exit($errorCount > 0 ? 1 : 0);
