<?php
/*
 * For pages that answer with <script>alert("..."); window.location = "..."</script>.
 *
 * Instead of the browser's own alert box, the message is queued in
 * sessionStorage and shown as the custom popup on the page the browser goes
 * to next (assets/js/loanpro.js, Popup). If sessionStorage cannot be used the
 * normal alert box still appears, so no message is ever lost.
 *
 * Include it at the top of the page, before any output.
 */
if (!defined('NS_ALERT_BRIDGE')) {
    define('NS_ALERT_BRIDGE', true);
    ob_start(static function (string $out): string {
        if (stripos($out, 'alert(') === false || stripos($out, '<script') === false) {
            return $out;
        }
        $shim = '<script>(function(){var n=window.alert;window.alert=function(m){try{'
            . 'var q=JSON.parse(sessionStorage.getItem("nsAlerts")||"[]");q.push(String(m));'
            . 'sessionStorage.setItem("nsAlerts",JSON.stringify(q));}catch(e){n.call(window,m);}};})();</script>';
        return (string)preg_replace('/<script\b/i', $shim . '<script', $out, 1);
    });
}
