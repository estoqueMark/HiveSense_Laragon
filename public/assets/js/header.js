
function toggleSidebar() {
    var sidebar  = document.querySelector('.sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var icon     = document.getElementById('sidebarToggleIcon');
    if (sidebar.classList.contains('open')) {
        closeSidebar();
    } else {
        sidebar.classList.add('open');
        backdrop.classList.add('active');
        icon.classList.remove('fa-bars');
        icon.classList.add('fa-times');
    }
}
function closeSidebar() {
    var sidebar  = document.querySelector('.sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var icon     = document.getElementById('sidebarToggleIcon');
    if (!sidebar) return;
    sidebar.classList.remove('open');
    backdrop.classList.remove('active');
    if (icon) {
        icon.classList.remove('fa-times');
        icon.classList.add('fa-bars');
    }
}

(function () {
    var main = document.querySelector('.main-content');
    var btn  = document.getElementById('sidebarToggle');
    if (!main || !btn) return;
    var lastY = 0;
    main.addEventListener('scroll', function () {
        var y = main.scrollTop;
        var sidebarOpen = document.querySelector('.sidebar.open');
        if (y > lastY && y > 80 && !sidebarOpen) btn.classList.add('hide');
        else btn.classList.remove('hide');
        lastY = y;
    }, { passive: true });
})();
