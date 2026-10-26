<?php
// cointopay_callback.php - relay only, do NOT load application_top.php here.

if (empty($_GET)) {
    http_response_code(400);
    exit('Invalid request');
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$fields = [];
foreach ($_GET as $key => $value) {
    if (is_scalar($value)) {
        $fields[$key] = $value;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex,nofollow">
    <title>Processing payment...</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f9f9f9; text-align:center; padding-top:15%; }
    </style>
</head>
<body>
    <h3>Processing your payment, please wait...</h3>

    <form id="ctpForm" method="post" action="cointopay_process.php">
        <?php foreach ($fields as $key => $value): ?>
            <input type="hidden" name="<?php echo h($key); ?>" value="<?php echo h($value); ?>">
        <?php endforeach; ?>
    </form>

    <script>
        document.getElementById('ctpForm').submit();
    </script>
</body>
</html>