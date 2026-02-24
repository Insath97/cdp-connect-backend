<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Investment Confirmation - CDP</title>
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
            letter-spacing: -0.025em;
        }

        .app-number {
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

        .status-banner {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 30px;
            text-transform: uppercase;
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

        .grid-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        .notes-box {
            background: #f1f5f9;
            padding: 15px;
            border-radius: 8px;
            font-size: 13px;
            color: var(--text-muted);
            font-style: italic;
        }

        .footer {
            background: #f8fafc;
            padding: 30px;
            text-align: center;
            font-size: 12px;
            color: var(--text-muted);
            border-top: 1px solid var(--border);
        }

        @media screen and (max-width: 600px) {
            .grid-container {
                grid-template-columns: 1fr;
            }

            .label {
                width: 50%;
            }
        }
    </style>
</head>

<body>
    <div class="email-wrapper">
        <div class="header">
            <h1 style="color: white; margin-bottom: 5px;">Investment Creation Successful</h1>
            <div class="app-number">{{ $data['application_number'] ?? 'N/A' }}</div>
        </div>

        <div class="content">
            <div class="status-banner">
                Status: Pending Approval
            </div>

            <!-- Important Summary -->
            <div class="section">
                <div class="section-title">Investment Summary</div>
                <table class="info-table">
                    <tr class="info-row">
                        <td class="label">Investment Amount</td>
                        <td class="value amount-highlight">LKR {{ number_format($data['investment_amount'] ?? 0, 2) }}
                        </td>
                    </tr>
                    <tr class="info-row">
                        <td class="label">Product Plan</td>
                        <td class="value">{{ $data['investment']->investmentProduct->product_name ?? 'N/A' }}
                            ({{ $data['investment']->investmentProduct->product_code ?? '' }})</td>
                    </tr>
                    <tr class="info-row">
                        <td class="label">ROI Percentage</td>
                        <td class="value">{{ number_format($data['investment']->interest_rate ?? 0, 2) }}% p.a.</td>
                    </tr>
                    <tr class="info-row">
                        <td class="label">Investment Date</td>
                        <td class="value">
                            {{ Carbon::parse($data['investment']->investment_date ?? now())->format('F d, Y') }}
                        </td>
                    </tr>
                    <tr class="info-row">
                        <td class="label">Sales Code</td>
                        <td class="value">{{ $data['sales_code'] ?? ($data['investment']->sales_code ?? 'N/A') }}</td>
                    </tr>
                </table>
            </div>

            <div class="grid-container">
                <!-- Customer Section -->
                <div class="section">
                    <div class="section-title">Customer Info</div>
                    <table class="info-table">
                        <tr class="info-row">
                            <td class="label">Name</td>
                            <td class="value">{{ $data['customer_name'] ?? 'N/A' }}</td>
                        </tr>
                        <tr class="info-row">
                            <td class="label">NIC</td>
                            <td class="value">{{ $data['investment']->customer->nic ?? 'N/A' }}</td>
                        </tr>
                        <tr class="info-row">
                            <td class="label">Phone</td>
                            <td class="value">{{ $data['investment']->customer->phone ?? 'N/A' }}</td>
                        </tr>
                    </table>
                </div>

                <!-- Branch Section -->
                <div class="section">
                    <div class="section-title">Branch Info</div>
                    <table class="info-table">
                        <tr class="info-row">
                            <td class="label">Branch</td>
                            <td class="value">{{ $data['branch_name'] ?? 'N/A' }}</td>
                        </tr>
                        <tr class="info-row">
                            <td class="label">Code</td>
                            <td class="value">{{ $data['investment']->branch->code ?? 'N/A' }}</td>
                        </tr>
                        <tr class="info-row">
                            <td class="label">Email</td>
                            <td class="value">{{ $data['investment']->branch->email ?? 'N/A' }}</td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Financial Details -->
            <div class="section">
                <div class="section-title">Bank & Payment Details</div>
                <table class="info-table">
                    <tr class="info-row">
                        <td class="label">Bank</td>
                        <td class="value">{{ $data['investment']->bank_name ?? 'N/A' }}</td>
                    </tr>
                    <tr class="info-row">
                        <td class="label">Account Number</td>
                        <td class="value">{{ $data['investment']->account_number ?? 'N/A' }}</td>
                    </tr>
                    <tr class="info-row">
                        <td class="label">Payment Type</td>
                        <td class="value">{{ $data['investment']->payment_type ?? 'N/A' }}</td>
                    </tr>
                </table>
            </div>

            <!-- Beneficiary Details -->
            <div class="section">
                <div class="section-title">Beneficiary Details</div>
                <table class="info-table">
                    <tr class="info-row">
                        <td class="label">Nominee</td>
                        <td class="value">{{ $data['investment']->nominee_name ?? 'N/A' }}</td>
                    </tr>
                    <tr class="info-row">
                        <td class="label">Relationship</td>
                        <td class="value">{{ $data['investment']->nominee_relationship ?? 'N/A' }}</td>
                    </tr>
                    <tr class="info-row">
                        <td class="label">Share (%)</td>
                        <td class="value">{{ number_format($data['investment']->nominee_share ?? 0, 2) }}%</td>
                    </tr>
                </table>
            </div>

            <!-- Notes -->
            <div class="section">
                <div class="section-title">Additional Notes</div>
                <div class="notes-box">
                    "{{ $data['investment']->notes ?? 'N/A' }}"
                </div>
            </div>

            @if(!empty($data['payment_proof']))
                <!-- Payment Proof -->
                <div class="section">
                    <div class="section-title">Payment Proof</div>
                    <div
                        style="text-align: center; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px dashed var(--border);">
                        <img src="{{ $message->embed(public_path('storage/' . $data['payment_proof'])) }}"
                            alt="Payment Proof"
                            style="max-width: 100%; height: auto; border-radius: 4px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                        <p style="font-size: 11px; color: var(--text-muted); margin-top: 10px;">Attached payment
                            confirmation document</p>
                    </div>
                </div>
            @endif
        </div>

        <div class="footer">
            <p>This is a system-generated email regarding your investment application.</p>
            <p>&copy; 2026 CDP Empire (Pvt) Ltd. All rights reserved.</p>
        </div>
    </div>
</body>

</html>
