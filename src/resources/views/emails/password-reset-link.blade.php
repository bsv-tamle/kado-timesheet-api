<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Password Reset</title>
</head>
<body style="font-family: Arial, sans-serif; color: #111827;">
  <h2>Password Reset Request</h2>
  <p>Hello {{ $user->full_name }},</p>
  <p>We received a request to reset your password.</p>
  <p>
    Click the link below to continue:
    <br />
    <a href="{{ $resetUrl }}">{{ $resetUrl }}</a>
  </p>
  <p>If you did not request this, you can ignore this email.</p>
</body>
</html>

