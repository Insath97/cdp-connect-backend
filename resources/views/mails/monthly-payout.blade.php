<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Payout - CDP</title>
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
            max-width: 600px;
            margin: 0 auto;
            background: var(--bg-card);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
        }

        .header {
            background-color: var(--primary);
            padding: 30px;
            text-align: center;
            color: #ffffff;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 800;
        }

        .content {
            padding: 30px;
        }

        .payout-card {
            background-color: var(--primary-light);
            padding: 25px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 25px;
        }

        .payout-amount {
            display: block;
            font-size: 32px;
            font-weight: 800;
            color: var(--primary);
            margin-top: 5px;
        }

        .payout-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--primary-dark);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .info-grid {
            border-top: 1px solid var(--border);
            padding-top: 20px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .label {
            color: var(--text-muted);
            font-weight: 500;
        }

        .value {
            color: var(--text-main);
            font-weight: 700;
        }

        .footer {
            background: #f8fafc;
            padding: 20px;
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
            <h1 style="color: white;">Monthly Payout Notification</h1>
        </div>

        <div class="content">
            <p>Dear {{ $data['customer_name'] }},</p>
            <p>We are pleased to inform you that your monthly investment return for <strong>{{ $data['month_year'] }}</strong> has been processed.</p>

            <div class="payout-card">
                <span class="payout-label">Your Return for this Month</span>
                <span class="payout-amount">LKR {{ number_format($data['payout_amount'], 2) }}</span>
            </div>

            <div class="info-grid">
                <div class="info-row">
                    <span class="label">Policy Number</span>
                    <span class="value">{{ $data['policy_number'] }}</span>
                </div>
                <div class="info-row">
                    <span class="label">Investment Product</span>
                    <span class="value">{{ $data['product_name'] }}</span>
                </div>
                <div class="info-row">
                    <span class="label">Investment Amount</span>
                    <span class="value">LKR {{ number_format($data['investment_amount'], 2) }}</span>
                </div>
                <div class="info-row">
                    <span class="label">Duration Progress</span>
                    <span class="value">Month {{ $data['completed_months'] }} of {{ $data['total_months'] }}</span>
                </div>
            </div>

            <p style="margin-top: 25px; font-size: 14px;">The amount will be credited to your registered bank account within 2-3 working days. Thank you for choosing CDP Connect.</p>
        </div>

        <div class="footer">
            <p>&copy; 2026 CDP Empire (Pvt) Ltd. All rights reserved.</p>
        </div>
    </div>
</body>

</html>
