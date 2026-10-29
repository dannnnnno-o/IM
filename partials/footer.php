<script src="/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
/* Auto-dismiss flash alerts (Sprint 13, 29 Oct 2026): success flashes carry
   .alert-dismissible; error alerts (alert-danger, no dismiss button, house
   pattern) are deliberately NOT in scope and stay until dismissed by hand.
   The .fade class on every alert makes Alert.close() ride the existing
   150ms transition, so the alert fades rather than popping out. */
(function(){
    const ALERT_AUTO_DISMISS_MS = 5000;   /* the single tuning knob */

    document.addEventListener("DOMContentLoaded", function(){
        if(!window.bootstrap){ return; }

        document.querySelectorAll(".alert-dismissible").forEach(function(alertEl){
            setTimeout(function(){
                /* getOrCreateInstance is REQUIRED here: an alert that was
                   merely rendered never has an instance (Alert instantiates
                   lazily on dismiss clicks), so a getInstance+guard skips
                   every timer. Closing early is safe: close() removes the
                   node, so a late close() on the detached element is an
                   invisible no-op — nothing can be resurrected. */
                bootstrap.Alert.getOrCreateInstance(alertEl).close();
            }, ALERT_AUTO_DISMISS_MS);
        });
    });
})();
</script>
</body>
</html>
