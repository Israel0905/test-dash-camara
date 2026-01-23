<?php
if (extension_loaded('sockets')) {
    echo "Sockets extension is loaded.\n";
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($socket) {
        echo "socket_create successful.\n";
        socket_close($socket);
    } else {
        echo "socket_create failed.\n";
    }
} else {
    echo "Sockets extension is NOT loaded.\n";
}
echo "PHP Version: " . phpversion() . "\n";
echo "Loaded Configuration File: " . php_ini_loaded_file() . "\n";
