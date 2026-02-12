<!DOCTYPE html>
<html>
<head>
    <title>Welcome to {{ config('app.name') }}</title>
</head>
<body>
    <h1>Welcome, {{ $user['name'] }}!</h1>
    <p>Your account has been created successfully.</p>

    <p><strong>Role:</strong> {{ $role }}</p>

    <p>Here are your login credentials:</p>
    <p>
        <strong>Email:</strong> {{ $user['email'] }}<br>
        <strong>Password:</strong> {{ $password }}
    </p>

    <p><strong>Created by:</strong> {{ $created_by }}</p>

    <p>Please login and change your password immediately.</p>
    <br>
    <p>Thank you,<br>{{ config('app.name') }} Team</p>
</body>
</html>
