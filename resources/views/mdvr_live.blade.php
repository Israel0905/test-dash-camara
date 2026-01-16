<!DOCTYPE html>
<html>
<head>
    <title>MDVR Live Monitor</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <h1>Consola MDVR en Tiempo Real</h1>
    <div id="log-container" style="background: #1e1e1e; color: #00ff00; padding: 20px; font-family: monospace; height: 500px; overflow-y: scroll;">
        <p>> Esperando datos de dispositivos...</p>
    </div>

    <script type="module">
        window.addEventListener('DOMContentLoaded', () => {
            window.Echo.channel('mdvr-terminal')
                .listen('MdvrMessageReceived', (e) => {
                    const container = document.getElementById('log-container');
                    const newLog = document.createElement('p');
                    
                    // Formateamos el JSON para que se vea limpio
                    const time = new Date().toLocaleTimeString();
                    newLog.innerText = `[${time}] DATA: ${JSON.stringify(e.data)}`;
                    
                    container.appendChild(newLog);
                    container.scrollTop = container.scrollHeight; // Auto-scroll
                });
        });
    </script>
</body>
</html>