<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to {{ config('app.name') }}</title>
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            max-width: 600px;
            margin: 40px auto;
            background: #ffffff;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        .header {
            background: linear-gradient(135deg, #298c77 0%, #3b82f6 100%);
            padding: 40px 20px;
            text-align: center;
            color: #ffffff;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.025em;
        }

        .content {
            padding: 40px;
        }

        .greeting {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 24px;
        }

        .user-type-badge {
            display: inline-block;
            padding: 6px 12px;
            background-color: #e8f0fe;
            color: #1e4b8c;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 16px;
        }

        .section-title {
            text-transform: uppercase;
            font-size: 12px;
            font-weight: 800;
            color: #64748b;
            letter-spacing: 0.1em;
            margin-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 8px;
        }

        .credential-box {
            background-color: #f1f5f9;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
        }

        .credential-item {
            display: flex;
            margin-bottom: 12px;
        }

        .credential-label {
            width: 100px;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
        }

        .credential-value {
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            word-break: break-all;
        }

        .hierarchy-box {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 32px;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }

        .location-item {
            background-color: #f8fafc;
            padding: 12px;
            border-radius: 8px;
        }

        .location-label {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .location-value {
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
        }

        .btn {
            display: block;
            background: #298c77;
            color: #ffffff;
            text-align: center;
            padding: 16px 32px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 16px;
            margin-top: 24px;
            transition: background 0.2s;
        }

        .footer {
            padding: 24px 40px;
            background-color: #f8fafc;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
        }

        .footer p {
            margin: 4px 0;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>WELCOME TO {{ strtoupper(config('app.name')) }}</h1>
        </div>
        <div class="content">
            <p class="greeting">Hello, {{ $user['name'] }}!</p>

            {{-- User Type Badge --}}
            <div class="user-type-badge">
                {{ ucfirst($user['user_type'] ?? 'User') }} Account
            </div>

            <p>Your professional account has been successfully created. You can now access the platform using the
                credentials below.</p>

            <div class="section-title">Access Credentials</div>
            <div class="credential-box">
                <div class="credential-item">
                    <div class="credential-label">Username</div>
                    <div class="credential-value">{{ $user['username'] }}</div>
                </div>
                <div class="credential-item">
                    <div class="credential-label">Email</div>
                    <div class="credential-value">{{ $user['email'] }}</div>
                </div>
                <div class="credential-item" style="margin-bottom: 0;">
                    <div class="credential-label">Password</div>
                    <div class="credential-value" style="color: #298c77;">{{ $password }}</div>
                </div>
            </div>

            {{-- Show Role if exists --}}
            @if (isset($role) && $role !== 'N/A' && !empty($role))
                <div class="section-title">Role & Permissions</div>
                <div class="hierarchy-box">
                    <div class="credential-item" style="margin-bottom: 0;">
                        <div class="credential-label">Role</div>
                        <div class="credential-value">{{ $role }}</div>
                    </div>
                </div>
            @endif

            {{-- Show Hierarchy Data only for hierarchy users --}}
            @if (isset($user['user_type']) && $user['user_type'] === 'hierarchy')
                <div class="section-title">Organizational Hierarchy</div>
                <div class="hierarchy-box">
                    {{-- Level --}}
                    @if (isset($level_name) && $level_name !== 'N/A' && !empty($level_name))
                        <div class="credential-item">
                            <div class="credential-label">Level</div>
                            <div class="credential-value">{{ $level_name }}</div>
                        </div>
                    @endif

                    {{-- Reports To (Parent) --}}
                    @if (isset($parent_name) && $parent_name !== 'N/A' && !empty($parent_name))
                        <div class="credential-item">
                            <div class="credential-label">Reports To</div>
                            <div class="credential-value">{{ $parent_name }}</div>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Show Location Data if any exists --}}
            @php
                $hasLocation =
                    (isset($branch_name) && $branch_name !== 'N/A' && !empty($branch_name)) ||
                    (isset($zone_name) && $zone_name !== 'N/A' && !empty($zone_name)) ||
                    (isset($region_name) && $region_name !== 'N/A' && !empty($region_name)) ||
                    (isset($province_name) && $province_name !== 'N/A' && !empty($province_name));
            @endphp

            @if ($hasLocation)
                <div class="section-title">Physical Location</div>
                <div class="grid-2">
                    @if (isset($branch_name) && $branch_name !== 'N/A' && !empty($branch_name))
                        <div class="location-item">
                            <div class="location-label">Branch</div>
                            <div class="location-value">{{ $branch_name }}</div>
                        </div>
                    @endif

                    @if (isset($zone_name) && $zone_name !== 'N/A' && !empty($zone_name))
                        <div class="location-item">
                            <div class="location-label">Zone</div>
                            <div class="location-value">{{ $zone_name }}</div>
                        </div>
                    @endif

                    @if (isset($region_name) && $region_name !== 'N/A' && !empty($region_name))
                        <div class="location-item">
                            <div class="location-label">Region</div>
                            <div class="location-value">{{ $region_name }}</div>
                        </div>
                    @endif

                    @if (isset($province_name) && $province_name !== 'N/A' && !empty($province_name))
                        <div class="location-item">
                            <div class="location-label">Province</div>
                            <div class="location-value">{{ $province_name }}</div>
                        </div>
                    @endif
                </div>
            @endif

            <a href="{{ $login_url ?? (config('app.frontend_url') ?? (config('app.url') ?? '#')) }}" class="btn">
                Login to Your Account
            </a>

            <p style="margin-top: 32px; font-size: 14px; line-height: 1.6;">
                <strong>Note:</strong> For security reasons, we recommend changing your password immediately after your
                first login.
            </p>

            {{-- Show email verification note if email not verified --}}
            {{--  @if (!isset($user['email_verified_at']) || !$user['email_verified_at'])
            <p style="margin-top: 16px; font-size: 13px; color: #dc2626;">
                ⚠️ Please verify your email address by clicking the link sent to your email inbox.
            </p>
            @endif --}}
        </div>
        <div class="footer">
            <p>This is an automated message. Please do not reply directly.</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
            <p>Created by: {{ $created_by }}</p>
        </div>
    </div>
</body>

</html>
