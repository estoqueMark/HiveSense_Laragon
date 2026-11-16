<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — HiveSense</title>
    <link rel="icon" type="image/png" href="<?= ROOT ?>/public/assets/img/cordillera-apiculture-logo.png">    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,700;1,700&family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ROOT ?>/public/assets/css/login.css">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
    <style>.swal2-container{z-index:20000!important}.swal2-popup{font-family:'Nunito',sans-serif}.swal2-popup.swal2-toast .swal2-title{font-size:.86rem;text-align:left}</style>
</head>
<body>

<div class="brand-panel">
    <div class="brand-content">
        <div class="brand-icon"><img src="<?= ROOT ?>/public/assets/img/cordillera-apiculture-logo.png" alt="Cordillera Regional Apiculture Center"></div>
        <h1 class="brand-title">HiveSense</h1>
        <div class="brand-divider"></div>
        <p class="brand-subtitle">Smart hive monitoring for the Cordillera Regional Apiculture Center.</p>
        <div class="brand-badges">
            <span class="badge"><i class="fas fa-thermometer-half"></i> Temperature</span>
            <span class="badge"><i class="fas fa-tint"></i> Humidity</span>
            <span class="badge"><i class="fas fa-calendar-alt"></i> Inspections</span>
        </div>
    </div>
</div>

<div class="form-panel">
    <div class="form-container">

        <a href="<?= ROOT ?>/" class="back-home">
            <i class="fas fa-arrow-left"></i> Back to CRAC Home
        </a>

        <div class="form-eyebrow">Welcome back</div>
        <h2 class="form-title">Sign in to HiveSense</h2>
        <p class="form-desc">Enter your credentials to access the dashboard.</p>

        <form method="POST" action="<?= ROOT ?>/login/submit" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

            <div class="field">
                <label for="login">Username or Email</label>
                <div class="input-wrap">
                    <input type="text" id="login" name="login"
                           placeholder="your username or email"
                           value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
                           autocomplete="username" required>
                    <i class="fas fa-user fi"></i>
                </div>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <input type="password" id="password" name="password"
                           placeholder="••••••••"
                           autocomplete="current-password" required>
                    <i class="fas fa-lock fi"></i>
                    <button type="button" class="toggle-pw" onclick="togglePw('password','pwIcon')">
                        <i class="fas fa-eye" id="pwIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>
        </form>

        <div class="divider"><span>or</span></div>

        <a href="<?= ROOT ?>/register" class="btn-register">
            <i class="fas fa-user-plus"></i> Create an Account
        </a>

        <p style="text-align:center;font-size:0.76rem;color:var(--text-dim);margin-top:18px;">
            <a href="<?= ROOT ?>/terms" style="color:var(--green);font-weight:700;text-decoration:none;">Terms of Service &amp; Privacy Policy</a>
        </p>

    </div>
</div>

<script>
function togglePw(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}
</script>
<script>
(function(){
  var T = Swal.mixin({toast:true, position:'top', showConfirmButton:false, showCloseButton:true, timerProgressBar:true,
    didOpen:function(t){t.addEventListener('mouseenter',Swal.stopTimer);t.addEventListener('mouseleave',Swal.resumeTimer);}});
  <?php if (!empty($error)): ?>T.fire({icon:'error', title:<?= json_encode($error) ?>, timer:5000});<?php endif; ?>
  <?php if (!empty($success)): ?>T.fire({icon:'success', title:<?= json_encode($success) ?>, timer:5000});<?php endif; ?>
})();

document.querySelector('form').addEventListener('submit', function () {
    var b = this.querySelector('.btn-submit');
    setTimeout(function () {
        b.disabled = true;
        b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing in...';
    }, 0);
});
window.addEventListener('pageshow', function (e) {
    if (e.persisted) location.reload();   // back button: don't leave it stuck on "Signing in"
});

</script>
</body>
</html>