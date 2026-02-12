<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to {{ config('app.name') }}</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f4f7;
            margin: 0;
            padding: 0;
        }
        .email-container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .email-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px 30px;
            text-align: center;
            color: #ffffff;
        }
        .email-header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }
        .email-body {
            padding: 40px 30px;
            color: #333333;
            line-height: 1.6;
        }
        .email-body h2 {
            color: #667eea;
            font-size: 22px;
            margin-top: 0;
        }
        .email-body p {
            font-size: 16px;
            margin: 15px 0;
        }
        .verification-button {
            display: inline-block;
            margin: 30px 0;
            padding: 15px 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            font-size: 16px;
            transition: transform 0.2s;
        }
        .verification-button:hover {
            transform: translateY(-2px);
        }
        .info-box {
            background-color: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .info-box p {
            margin: 5px 0;
            font-size: 14px;
            color: #555555;
        }
        .email-footer {
            background-color: #f8f9fa;
            padding: 30px;
            text-align: center;
            color: #666666;
            font-size: 14px;
        }
        .email-footer p {
            margin: 5px 0;
        }
        .divider {
            height: 1px;
            background-color: #e0e0e0;
            margin: 30px 0;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="email-header">
            <h1>Welcome to {{ config('app.name') }}!</h1>
        </div>

        <!-- Body -->
        <div class="email-body">
            <h2>Hello {{ $customerName }},</h2>
            
            <p>Thank you for registering with <strong>{{ config('app.name') }}</strong>! We're excited to have you on board.</p>
            
            <p>To complete your registration and start shopping, please verify your email address by clicking the button below:</p>
            
            <div style="text-align: center;">
                <a href="{{ $verificationUrl }}" class="verification-button">Verify Email Address</a>
            </div>

            <div class="info-box">
                <p><strong>⏰ Important:</strong> This verification link will expire in <strong>24 hours</strong>.</p>
                <p>If you didn't create an account with {{ config('app.name') }}, please ignore this email.</p>
            </div>

            <div class="divider"></div>

            <p style="font-size: 14px; color: #666666;">
                If the button above doesn't work, copy and paste the following link into your browser:
            </p>
            <p style="font-size: 13px; color: #667eea; word-break: break-all;">
                {{ $verificationUrl }}
            </p>
        </div>

        <!-- Footer -->
        <div class="email-footer">
            <p><strong>{{ config('app.name') }}</strong></p>
            <p>Your trusted e-commerce partner</p>
            <p style="margin-top: 15px;">
                Need help? Contact us at <a href="mailto:{{ config('mail.from.address') }}" style="color: #667eea; text-decoration: none;">{{ config('mail.from.address') }}</a>
            </p>
            <p style="margin-top: 20px; font-size: 12px; color: #999999;">
                © {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>
