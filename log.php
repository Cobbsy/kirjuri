<?php
// Authenticate before any output so that the redirect headers can still be sent.
require_once './include_functions.php';
ksess_verify(0);
?>
<html>
<head>
</head>
<body>
	<h1>kirjuri.log</h1>
<pre>
<?php
$log = file_exists('logs/kirjuri.log') ? file('logs/kirjuri.log') : array();
$log = array_reverse($log);
foreach ($log as $line) {
    echo htmlspecialchars(str_replace(";", " ", $line));
}
?>
</pre>
</body>
</html>
