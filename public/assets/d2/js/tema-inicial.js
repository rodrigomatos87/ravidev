// Primeira pintura já no tema certo: lê a preferência deste navegador (claro, escuro ou
// automático pelo sistema) e marca html[data-theme] antes de o CSS desenhar a tela.
// Script clássico e síncrono no head; o resto fica em js/casca.js.
(function () {
    var raiz = document.documentElement;
    var pref = 'auto';
    try {
        var salvo = localStorage.getItem('ravidev.tema');
        if (salvo === 'claro' || salvo === 'escuro' || salvo === 'auto') pref = salvo;
    } catch (e) { /* navegador sem armazenamento */ }
    var escuro = pref === 'escuro' || (pref === 'auto' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    raiz.setAttribute('data-theme', escuro ? 'escuro' : 'claro');
    raiz.style.colorScheme = escuro ? 'dark' : 'light';
}());
