
<?php
// Send a 503 Service Unavailable header to protect SEO ranking during construction
header('HTTP/1.1 503 Service Unavailable');
header('Retry-After: 3600'); // Suggests checking back in 1 hour
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Under Construction | engage.dcwwiki.org</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f4f6f9;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            text-align: center;
        }
        .container {
            max-width: 500px;
            padding: 40px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .logo {
            font-size: 1.2rem;
            font-weight: 700;
            color: #4A5568;
            letter-spacing: 0.05em;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 2.5rem;
            color: #2D3748;
            margin: 0 0 15px 0;
        }
        p {
            font-size: 1.1rem;
            color: #718096;
            line-height: 1.6;
            margin: 0 0 30px 0;
        }
        .gear-icon {
            font-size: 3rem;
            margin-bottom: 20px;
            animation: spin 4s linear infinite;
            display: inline-block;
        }
        @keyframes spin {
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="logo">engage.dcwwki.org</div>
        <div class="gear-icon">⚙️</div>
        <h1>Under Construction</h1>
        <p>DCW volunteers are currently building something exciting. Please check back soon!</p>
    </div>

</body>
</html>
