
</main>
<footer class="site-footer">
    <div class="footer-inner">
        <div class="footer-col">
            <p class="brand-mark footer-brand">
                <img src="assets/images/brand/brewed-delights-logo.png" alt="" class="brand-logo">
                <span>Brewed&nbsp;Delights</span>
            </p>
            <p class="footer-note">Small-batch, roasted weekly — brewed daily, served warm.</p>
        </div>
        <div class="footer-col">
            <h4>Hours</h4>
            <p>Mon–Sat: Open 24 Hours<br>Sunday: 7:00 AM – 11:00 PM</p>
        </div>
        <div class="footer-col">
            <h4>Visit</h4>
            <address>
                <a href="https://maps.google.com/?q=%23215+Manila+South+Rd.+corner+Golden+City+Subd.+Entrance%2C+BRGY.+Dila%2C+Santa+Rosa+City%2C+Laguna%2C+Philippines+4026" target="_blank" rel="noopener noreferrer">
                    #215 Manila South Rd. corner Golden City Subd. Entrance, BRGY. Dila, Santa Rosa City, Laguna (Across Jollibee Golden City), Santa Rosa, Philippines, 4026
                </a>
            </address>
        </div>
        <div class="footer-col">
            <h4>Social media</h4>
            <p>
                Facebook: <a href="https://www.facebook.com/BrewedDelights.GoldenSantaRosa" target="_blank" rel="noopener noreferrer">Brewed Delights Golden Santa Rosa</a><br>
                Gmail: <a href="mailto:breweddelights.balibago@gmail.com">breweddelights.balibago@gmail.com</a>
            </p>
        </div>
        <div class="footer-col">
            <h4>Explore</h4>
            <p><a href="index.php">Menu</a><br>
                <?php if (!empty($user)): ?>
                    <a href="account.php"><?= e($user['name']) ?></a>
                <?php else: ?>
                    <a href="login.php">Log in</a><br><a href="register.php">Sign up</a>
                <?php endif; ?>
            </p>
        </div>
    </div>
    <p class="footer-copy">&copy; <?= date('Y') ?> Brewed Delights Coffee Shop.</p>
</footer>
<button type="button" class="back-to-top" data-back-to-top aria-label="Back to top">
    <span class="back-to-top-label">Back to top</span><span aria-hidden="true">↑</span>
</button>
<script src="assets/js/theme.js?v=2"></script>
<script src="assets/js/script.js?v=11"></script>
</body>
</html>
