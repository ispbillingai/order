<?php
/**
 * Receipt for Printing
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin', 'cashier']);

$orderId = $_GET['order'] ?? null;
if (!$orderId) {
    exit('No order specified');
}

$order = getOrderById($orderId);
if (!$order) {
    exit('Order not found');
}

$orderItems = getOrderItems($orderId);

// Get payment info
$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT * FROM payments WHERE order_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$orderId]);
$payment = $stmt->fetch();

// Get workspace info
$stmt = $pdo->query("SELECT * FROM workspaces LIMIT 1");
$workspace = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt - <?= htmlspecialchars($order['order_number']) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            width: 80mm;
            padding: 10px;
            background: white;
        }
        
        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 1px dashed #000;
            padding-bottom: 10px;
        }
        
        .header h1 {
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .header p {
            font-size: 10px;
        }
        
        .info {
            margin-bottom: 15px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
        }
        
        .items {
            border-top: 1px dashed #000;
            border-bottom: 1px dashed #000;
            padding: 10px 0;
            margin-bottom: 10px;
        }
        
        .item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .item-name {
            flex: 1;
        }
        
        .item-qty {
            width: 30px;
            text-align: center;
        }
        
        .item-price {
            width: 60px;
            text-align: right;
        }
        
        .totals {
            margin-bottom: 15px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }
        
        .total-row.grand {
            font-size: 16px;
            font-weight: bold;
            border-top: 1px solid #000;
            padding-top: 5px;
            margin-top: 5px;
        }
        
        .payment-info {
            border-top: 1px dashed #000;
            padding-top: 10px;
            margin-bottom: 15px;
        }
        
        .footer {
            text-align: center;
            font-size: 10px;
            border-top: 1px dashed #000;
            padding-top: 10px;
        }
        
        @media print {
            body {
                width: 80mm;
            }
            
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><?= htmlspecialchars($workspace['name'] ?? 'Restaurant') ?></h1>
        <p>Thank you for dining with us!</p>
    </div>
    
    <div class="info">
        <div class="info-row">
            <span>Order:</span>
            <span><?= htmlspecialchars($order['order_number']) ?></span>
        </div>
        <div class="info-row">
            <span>Table:</span>
            <span><?= htmlspecialchars($order['table_number']) ?> (<?= htmlspecialchars($order['room_name']) ?>)</span>
        </div>
        <div class="info-row">
            <span>Date:</span>
            <span><?= date('d/m/Y H:i', strtotime($order['opened_at'])) ?></span>
        </div>
        <div class="info-row">
            <span>Guests:</span>
            <span><?= $order['number_of_people'] ?></span>
        </div>
        <div class="info-row">
            <span>Server:</span>
            <span><?= htmlspecialchars($order['waiter_name']) ?></span>
        </div>
    </div>
    
    <div class="items">
        <div class="item" style="font-weight: bold; margin-bottom: 8px;">
            <span class="item-name">Item</span>
            <span class="item-qty">Qty</span>
            <span class="item-price">Price</span>
        </div>
        
        <?php foreach ($orderItems as $item): ?>
            <div class="item">
                <span class="item-name"><?= htmlspecialchars($item['item_name']) ?></span>
                <span class="item-qty"><?= $item['quantity'] ?></span>
                <span class="item-price"><?= number_format($item['total_price'], 2) ?></span>
            </div>
        <?php endforeach; ?>
        
        <div class="item">
            <span class="item-name">Cover Charge</span>
            <span class="item-qty"><?= $order['number_of_people'] ?></span>
            <span class="item-price"><?= number_format($order['number_of_people'] * $order['cover_charge_per_person'], 2) ?></span>
        </div>
    </div>
    
    <div class="totals">
        <div class="total-row">
            <span>Subtotal:</span>
            <span><?= number_format($order['subtotal'], 2) ?></span>
        </div>
        
        <?php if ($order['discount_amount'] > 0): ?>
            <div class="total-row">
                <span>Discount:</span>
                <span>-<?= number_format($order['discount_amount'], 2) ?></span>
            </div>
        <?php endif; ?>
        
        <div class="total-row grand">
            <span>TOTAL:</span>
            <span><?= CURRENCY_SYMBOL ?><?= number_format($order['total'], 2) ?></span>
        </div>
    </div>
    
    <?php if ($payment): ?>
        <div class="payment-info">
            <div class="total-row">
                <span>Payment Method:</span>
                <span><?= ucfirst($payment['method']) ?></span>
            </div>
            <div class="total-row">
                <span>Amount Paid:</span>
                <span><?= CURRENCY_SYMBOL ?><?= number_format($payment['amount'], 2) ?></span>
            </div>
            <?php if ($payment['amount'] > $order['total']): ?>
                <div class="total-row">
                    <span>Change:</span>
                    <span><?= CURRENCY_SYMBOL ?><?= number_format($payment['amount'] - $order['total'], 2) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($payment['reference']): ?>
                <div class="total-row">
                    <span>Reference:</span>
                    <span><?= htmlspecialchars($payment['reference']) ?></span>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <div class="footer">
        <p>Thank you for visiting!</p>
        <p>Please come again</p>
        <p style="margin-top: 10px;">
            <?= date('d/m/Y H:i:s') ?>
        </p>
    </div>
    
    <div class="no-print" style="margin-top: 20px; text-align: center;">
        <button onclick="window.print()" style="padding: 10px 20px; font-size: 14px; cursor: pointer;">
            Print Receipt
        </button>
        <button onclick="window.close()" style="padding: 10px 20px; font-size: 14px; cursor: pointer; margin-left: 10px;">
            Close
        </button>
    </div>
    
    <script>
        // Auto-print
        window.onload = function() {
            // window.print();
        };
    </script>
</body>
</html>
