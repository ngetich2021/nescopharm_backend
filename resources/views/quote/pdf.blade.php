<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Quote #{{ $quote->quote_number }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
body { font-family: 'Figtree'; background: #fff; }
        .quote-box {
            max-width: 760px;
            margin: 24px auto;
            padding: 36px 32px 32px 32px;
        }
        .flex-row { display: flex; justify-content: space-between; align-items: flex-start; }
        .company-logo {
            width: 56px; height: 56px; border-radius: 8px; background: #111827; color: #fff;
            display: flex; align-items: flex-start; justify-content: center; font-size: 2rem; font-weight: bold;
            margin-right: 12px; margin-top: 0; margin-bottom: 0;
        }
        .company-info { margin-bottom: 0; margin-top: 0; }
        .company-name { font-size: 1.3rem; font-weight: 700; color: #232c36; }
        .company-type { color: #6b7280; font-size: 1rem; font-weight: 400; }
        .quote-title { font-size: 2.1rem; font-weight: 700; color: #232c36; letter-spacing: 1px; text-align: right; }
        .prepared-for { font-weight: 700; color: #232c36; font-size: 1.15rem; margin-bottom: 8px; }
        .header-table { width: 100%; margin-top: 36px; margin-bottom: 12px; border-collapse: collapse; }
        .header-table td { vertical-align: top; padding: 0; }
        .header-left { width: 50%; }
        .header-right { width: 50%; text-align: right; }
        .header-right .label { font-weight: 500; color: #232c36; }
        .header-right .value { font-weight: 600; color: #232c36; margin-left: 8px; }
        .header-right .row { margin-bottom: 4px; font-size: 1.08rem; }
        .items-table { width: 100%; border-collapse: collapse; margin-top: 32px; }
        .items-table th, .items-table td { border-bottom: 1px solid #e5e7eb; padding: 10px 6px; }
        .items-table th { background: none; color: #232c36; font-size: 1.05rem; font-weight: 600; }
        .items-table th:nth-child(1), .items-table td:nth-child(1) { text-align: left; }
        .items-table th:nth-child(2), .items-table th:nth-child(3), .items-table th:nth-child(4),
        .items-table td:nth-child(2), .items-table td:nth-child(3), .items-table td:nth-child(4) { text-align: right; }
        .items-table td { font-size: 1.05rem; }
        .summary-table { width: 40%; float: right; margin-top: 18px; }
        .summary-table td { padding: 4px 0; font-size: 1.05rem; }
        .summary-table .total-label { font-weight: 700; font-size: 1.15rem; }
        .summary-table .total-value { font-weight: 700; font-size: 1.15rem; }
        .footer { margin-top: 56px; font-size: 1.05rem; color: #6b7280; }
        .valid-until { margin-top: 20px; padding: 12px 20px; background: #fef3c7; border-radius: 8px; font-weight: 600; color: #92400e; }
    </style>
</head>
<body>
    <div class="quote-box">
        <div class="flex-row" style="align-items: flex-start; margin-bottom: 0;">
            <div style="display: flex; align-items: flex-start; margin-top: 0; margin-bottom: 0;">
                @if(isset($quote->company) && $quote->company->logo_url)
                    <img src="{{ $quote->company->logo_url }}" alt="Logo" class="company-logo" style="background: none;"/>
                @else
                    <div class="company-logo">
                        {{ strtoupper(substr($quote->company->name ?? 'C', 0, 1)) }}
                    </div>
                @endif
                <div class="company-info" style="margin-left: 12px;">
                    <div class="company-name">{{ $quote->company->name ?? 'Company Name' }}</div>
                </div>
            </div>
            <div class="quote-title" style="text-align: right; min-width: 180px; align-self: flex-start; margin-top: 0;">QUOTATION</div>
        </div>

        <table class="header-table">
            <tr>
                <td class="header-left">
                    <div class="prepared-for">PREPARED FOR:</div>
                    <div style="font-size: 1.08rem; font-weight: 500; color: #232c36;">
                        {{ $quote->customer->name ?? '' }}<br>
                        {{ $quote->customer->phone ?? '' }}
                    </div>
                </td>
                <td class="header-right">
                    <div class="row"><span class="label">Quote No.</span> <span class="value">{{ $quote->quote_number }}</span></div>
                    <div class="row"><span class="label">Date:</span> <span class="value">{{ \Carbon\Carbon::parse($quote->created_at)->format('j M Y') }}</span></div>
                    <div class="row"><span class="label">Valid Until:</span> <span class="value">{{ \Carbon\Carbon::parse($quote->valid_until)->format('j M Y') }}</span></div>
                </td>
            </tr>
        </table>

        <table class="items-table">
            <thead>
                <tr>
                    <th>Item Description</th>
                    <th>Quantity</th>
                    <th>Unit Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($quote->quoteItems as $item)
                <tr>
                    <td>{{ $item->product->name ?? '' }}{{ $item->variant ? ' - ' . $item->variant->name : '' }}</td>
                    <td>{{ number_format($item->quantity, 2) }}</td>
                    <td>{{ $quote->currency }} {{ number_format($item->unit_price, 2) }}</td>
                    <td>{{ $quote->currency }} {{ number_format($item->total_price, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <table class="summary-table">
            <tr>
                <td>Subtotal</td>
                <td style="text-align:right;">{{ $quote->currency }} {{ number_format($quote->total_amount, 2) }}</td>
            </tr>
            @if($quote->discount > 0)
            <tr>
                <td>Discount</td>
                <td style="text-align:right;">-{{ $quote->currency }} {{ number_format($quote->discount, 2) }}</td>
            </tr>
            @endif
            <tr>
                <td class="total-label">Total</td>
                <td class="total-value" style="text-align:right;">{{ $quote->currency }} {{ number_format($quote->final_amount, 2) }}</td>
            </tr>
        </table>

        <div style="clear: both;"></div>

        @if($quote->valid_until)
        <div class="valid-until">
            This quote is valid until {{ \Carbon\Carbon::parse($quote->valid_until)->format('F j, Y') }}
        </div>
        @endif

        @if($quote->notes)
        <div class="footer">
            <div style="font-weight: 600; margin-bottom: 8px;">Notes:</div>
            <div>{{ $quote->notes }}</div>
        </div>
        @else
        <div class="footer">
            <div style="margin-top: 8px;">Thank you for considering our quotation.</div>
        </div>
        @endif
        
    </div>
</body>
</html>
