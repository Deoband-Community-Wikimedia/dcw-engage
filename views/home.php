<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Under Construction - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #106b9a;
            --primary-hover: #0c567a;
            --secondary-color: #97161b;
            --background: #f4f6f8;
            --card-bg: #ffffff;
            --text-color: #1e293b;
            --border-color: #e2e8f0;
            --muted-text: #64748b;
        }

        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--background);
            font-family: 'Inter', sans-serif;
            color: var(--text-color);
        }

        .card {
            background: var(--card-bg);
            padding: 48px 60px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            text-align: center;
            max-width: 520px;
            width: 90%;
            border: 1px solid var(--border-color);
        }

        .domain {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 24px;
            letter-spacing: 0.5px;
            opacity: 0.8;
        }

        .icon-container {
            margin: 24px 0;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .gear-icon {
            width: 56px;
            height: 56px;
            fill: #b3a4c4; 
            animation: spin 8s linear infinite;
        }

        h1 {
            font-size: 32px;
            font-weight: 700;
            margin: 0 0 16px 0;
            color: var(--text-color);
        }

        p {
            font-size: 15px;
            color: var(--muted-text);
            line-height: 1.6;
            margin: 0;
        }

        @keyframes spin {
            100% {
                transform: rotate(360deg);
            }
        }
    </style>
</head>
<body>

    <div class="card">
        <div class="domain">engage.dcwwiki.org</div>
        
        <div class="icon-container">
            <svg class="gear-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.56-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.73 9.05c-.12.21-.08.47.12.61l2.03 1.58c-.04.3-.09.61-.09.94s.05.64.09.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .43-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/>
                <circle cx="12" cy="12" r="2.5" fill="#ffffff" />
            </svg>
        </div>

        <h1>Under Construction</h1>
        <p>DCW volunteers are currently building something exciting.<br>Please check back soon!</p>
    </div>

</body>
</html>
