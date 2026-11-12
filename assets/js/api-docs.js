/* ============================================================================
 * api-docs.js — منطق صفحة التوثيق (مستخرج من api-docs.php في المرحلة 3 — M3)
 * ============================================================================ */

function toggleEndpoint(header) {
    var endpoint = header.parentElement;
    endpoint.classList.toggle('open');
}

function copyCode(btn) {
    var codeBlock = btn.closest('.code-block');
    var text = codeBlock.textContent
        .replace(/^نسخ$/, '')
        .replace(/^\w+\n/, '')
        .trim();
    
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            btn.textContent = 'تم النسخ!';
            setTimeout(function() { btn.textContent = 'نسخ'; }, 2000);
        });
    } else {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            btn.textContent = 'تم النسخ!';
            setTimeout(function() { btn.textContent = 'نسخ'; }, 2000);
        } catch(e) {
            btn.textContent = 'خطأ!';
        }
        document.body.removeChild(textarea);
    }
}

// Active nav highlighting on scroll
var sections = document.querySelectorAll('.section');
var navLinks = document.querySelectorAll('.api-nav a');

function updateActiveNav() {
    var scrollPos = window.scrollY + 100;
    sections.forEach(function(section) {
        if (section.offsetTop <= scrollPos && (section.offsetTop + section.offsetHeight) > scrollPos) {
            var id = section.getAttribute('id');
            navLinks.forEach(function(link) {
                link.classList.remove('active');
                if (link.getAttribute('href') === '#' + id) {
                    link.classList.add('active');
                }
            });
        }
    });
}

window.addEventListener('scroll', updateActiveNav);

// Smooth scroll for nav links
navLinks.forEach(function(link) {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        var target = document.querySelector(this.getAttribute('href'));
        if (target) {
            var navHeight = document.querySelector('.api-nav').offsetHeight;
            var targetPos = target.offsetTop - navHeight - 10;
            window.scrollTo({ top: targetPos, behavior: 'smooth' });
        }
    });
});
