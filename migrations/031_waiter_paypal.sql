-- Migration: 031_waiter_paypal
-- Each waiter's PayPal.me name, for the guests' tips: the link (and its QR)
-- goes with the bill on WhatsApp, on the receipt and on the table page.

ALTER TABLE users ADD COLUMN paypal_me VARCHAR(40) NULL DEFAULT NULL;
