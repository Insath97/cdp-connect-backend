<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Investment Approved - CDP</title>
    <style>
        :root {
            --primary: #298c77;
            --primary-light: #e1f8f3;
            --primary-dark: #1e594f;
            --text-main: #334155;
            --text-muted: #64748b;
            --bg-page: #f8fafc;
            --bg-card: #ffffff;
            --border: #e2e8f0;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-page);
            margin: 0;
            padding: 20px;
            color: var(--text-main);
            line-height: 1.6;
        }

        .email-wrapper {
            max-width: 650px;
            margin: 0 auto;
            background: var(--bg-card);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
        }

        .header {
            background-color: var(--primary);
            padding: 40px 30px;
            text-align: center;
            color: #ffffff;
        }

        .header h1 {
            margin: 0;
            font-size: 26px;
            font-weight: 800;
        }

        .welcome-badge {
            display: inline-block;
            margin-top: 12px;
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.05em;
        }

        .content {
            padding: 30px;
        }

        .congrats-card {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 30px;
        }

        .congrats-card h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
        }

        .section {
            margin-bottom: 35px;
        }

        .section-title {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            color: var(--primary);
            letter-spacing: 0.1em;
            margin-bottom: 15px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 6px;
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
        }

        .info-row td {
            padding: 10px 0;
            font-size: 14px;
            border-bottom: 1px solid #f1f5f9;
        }

        .label {
            color: var(--text-muted);
            font-weight: 500;
            width: 40%;
        }

        .value {
            color: var(--text-main);
            font-weight: 600;
            text-align: right;
        }

        .amount-highlight {
            font-size: 20px;
            color: var(--primary);
        }

        .footer {
            background: #f8fafc;
            padding: 30px;
            text-align: center;
            font-size: 12px;
            color: var(--text-muted);
            border-top: 1px solid var(--border);
        }
    </style>
</head>

<body>
    <div class="email-wrapper">
        <div class="header">
            <h1 style="color: white; margin-bottom: 5px;">Investment Approved</h1>
            <div class="welcome-badge">Welcome to the CDP Family!</div>
        </div>

        <div class="content">
            <div class="congrats-card">
                <h2>Congratulations, {{ $data['customer_name'] }}!</h2>
                <p style="margin: 5px 0 0 0;">Your investment application has been officially approved and your and your policy is now active.</p>
            </div>

            <div class="section">
                <div class="section-title">Policy Details</div>
                <table class="info-table">
                    <tr class="info-row">
                        <td class="label">Policy Number</td>
                        <td class="value">{{ $data['policy_number'] }}</td>
                    </tr>
                    <tr class="info-row">
                        <td class="label">Investment Amount</td>
                        <td class="value amount-highlight">LKR {{ number_format($data['investment_amount'], 2) }}</td>
                    </tr>
                    <tr class="info-row">
                        <td class="label">Product Plan</td>
                        <td class="value">{{ $data['product_name'] }}</td>
                    </tr>
                    <tr class="info-row">
                        <td class="label">Duration</td>
                        <td class="value">{{ $data['duration_months'] }} Months</td>
                    </tr>
                </table>
            </div>

            <p>Your monthly returns will be processed on the {{ $data['monthly_payout_day'] }}th of every month. You will receive a notification via Email and SMS once each payout is processed.</p>
            
            <p style="margin-top: 25px;">Thank you for trusting CDP Connect with your investments. We look forward to a successful partnership.</p>
        </div>

        <div class="footer">
            <p>This is a system-generated email. Please do not reply to this message.</p>
            <p>&copy; 2026 CDP Empire (Pvt) Ltd. All rights reserved.</p>
        </div>
    </div>
</body>

</html>
