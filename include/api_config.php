<?php
/**
 * API Security Configuration
 * API Key hash is generated with password_hash() and verified with password_verify()
 */

// API Key hash — the raw key is stored in the Flutter app
// Raw key: configured in Flutter app's api_config.dart
define('API_KEY_HASH', '$2y$12$2f9sFlP66GRkoab4jFGOnuAm32loDn7wS4bT9PwpZ4p0fjmcroSma');

// Rate limiting
define('API_RATE_LIMIT_AUTH', 5);       // Max auth attempts per window
define('API_RATE_LIMIT_WINDOW', 300);   // Window in seconds (5 minutes)

// Firebase Cloud Messaging (FCM)
// Get server key from: Firebase Console > Project Settings > Cloud Messaging > Server Key
define('FCM_SERVER_KEY', '');

// Agora.io (Intercom/Video Calling)
// Get from: console.agora.io > Project > App ID / App Certificate
define('AGORA_APP_ID', '');
define('AGORA_APP_CERTIFICATE', '');

// WhatsApp Notifications (optional)
// Provider: 'gupshup' or 'twilio'
define('WA_PROVIDER', '');
define('WA_API_KEY', '');
define('WA_FROM_NUMBER', '');
