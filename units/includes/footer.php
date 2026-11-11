
    <div class="footer">
        <p>تطبيق إدارة المخزون &copy; <?php echo date('Y'); ?> - جميع الحقوق محفوظة</p>
    </div>

    <script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
    function toggleMobileMenu() {
        const nav = document.getElementById('mainNav');
        const btn = document.getElementById('mobileMenuBtn');
        const overlay = document.getElementById('mobileOverlay');
        
        nav.classList.toggle('mobile-open');
        btn.classList.toggle('active');
        overlay.classList.toggle('active');
        document.body.classList.toggle('menu-open');
    }

    // إغلاق القائمة عند تغيير حجم الشاشة
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            const nav = document.getElementById('mainNav');
            const btn = document.getElementById('mobileMenuBtn');
            const overlay = document.getElementById('mobileOverlay');
            if (nav) nav.classList.remove('mobile-open');
            if (btn) btn.classList.remove('active');
            if (overlay) overlay.classList.remove('active');
            document.body.classList.remove('menu-open');
        }
    });

    // إغلاق القائمة المنسدلة للمستخدم عند النقر خارجها
    document.addEventListener('click', function(e) {
        const userMenu = document.querySelector('.user-menu');
        if (userMenu) {
            const userInfo = userMenu.querySelector('.user-info');
            const dropdown = userMenu.querySelector('.user-dropdown');
            if (userInfo && dropdown) {
                if (!userMenu.contains(e.target)) {
                    userInfo.classList.remove('open');
                }
            }
        }
    });

    // إغلاق القائمة عند النقر على عنصر في الهاتف
    document.addEventListener('DOMContentLoaded', function() {
        if (window.innerWidth <= 768) {
            // إغلاق عند النقر على رابط مباشر
            const navLinks = document.querySelectorAll('#mainNav > a');
            navLinks.forEach(function(link) {
                link.addEventListener('click', function() {
                    closeMobileMenu();
                });
            });

            // إغلاق عند النقر على رابط داخل قائمة منسدلة
            const dropdownLinks = document.querySelectorAll('#mainNav .nav-dropdown-menu a');
            dropdownLinks.forEach(function(link) {
                link.addEventListener('click', function() {
                    closeMobileMenu();
                });
            });

            // فتح تلقائي للقائمة المنسدلة النشطة
            const activeDropdowns = document.querySelectorAll('#mainNav .nav-dropdown.active-parent');
            activeDropdowns.forEach(function(dd) {
                dd.classList.add('open');
            });
        }

        // إغلاق القوائم المنسدلة عند النقر خارجها (ديسكتوب)
        document.addEventListener('click', function(e) {
            const dropdowns = document.querySelectorAll('.navbar .nav-dropdown');
            dropdowns.forEach(function(dd) {
                if (!dd.contains(e.target)) {
                    dd.classList.remove('open');
                }
            });
        });
    });

    function closeMobileMenu() {
        const nav = document.getElementById('mainNav');
        const btn = document.getElementById('mobileMenuBtn');
        const overlay = document.getElementById('mobileOverlay');
        if (nav) nav.classList.remove('mobile-open');
        if (btn) btn.classList.remove('active');
        if (overlay) overlay.classList.remove('active');
        document.body.classList.remove('menu-open');
        // إغلاق قائمة المستخدم المنسدلة أيضاً
        const userDropdown = document.getElementById('mobileUserDropdown');
        if (userDropdown) userDropdown.classList.remove('open');
    }

    // تبديل قائمة المستخدم المنسدلة في الهاتف
    function toggleMobileUserMenu() {
        const dropdown = document.getElementById('mobileUserDropdown');
        if (dropdown) {
            dropdown.classList.toggle('open');
        }
    }
    </script>
</body>
</html>
