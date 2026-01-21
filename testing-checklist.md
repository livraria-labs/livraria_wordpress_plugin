# Testing Checklist for Courier Expedition Plugin

## Pre-Testing Setup

### WordPress Environment
- [ ] WordPress 5.0+ installed
- [ ] WooCommerce 3.0+ activated
- [ ] Plugin activated successfully
- [ ] No PHP errors in error logs

### API Environment  
- [ ] Courier API running (check with `npm run start:web:dev`)
- [ ] Database accessible (PostgreSQL + MongoDB)
- [ ] Valid API credentials obtained
- [ ] Test courier configurations available

## Configuration Testing

### Plugin Settings
- [ ] Navigate to Settings → Courier API
- [ ] Enter API Base URL (e.g., `http://localhost:3000`)
- [ ] Enter valid API token
- [ ] Configure default sender information
- [ ] Add sender address in JSON format
- [ ] Click "Test Connection" → should show success
- [ ] Save settings

### Sample Sender Address JSON
```json
{
  "country": "Romania",
  "county": "Bucharest", 
  "city": "Bucharest",
  "postcode": "010101",
  "street": "Test Street",
  "streetNumber": "123",
  "block": "A",
  "staircase": "1", 
  "floor": "2",
  "apartment": "3",
  "localityPostcode": "010101"
}
```

## Functional Testing

### Manual Expedition Creation
1. **Create Test Order**
   - [ ] Add product to cart with weight/dimensions
   - [ ] Complete checkout with valid shipping address
   - [ ] Order status should be "Processing"

2. **Manual Creation**
   - [ ] Go to WooCommerce → Orders
   - [ ] Edit the test order
   - [ ] Find "Courier Expedition" metabox in sidebar
   - [ ] Click "Create Expedition" button
   - [ ] Should show "Creating expedition..." loading state
   - [ ] Success: Shows expedition ID and AWB number
   - [ ] Failure: Shows error message with details

3. **Verify Data Storage**
   - [ ] Order meta should contain `_courier_expedition_id`
   - [ ] Order meta should contain `_courier_awb_number` (if generated)
   - [ ] Order notes should show expedition creation log
   - [ ] Refresh page → expedition info should persist

### Automatic Expedition Creation
1. **Enable Auto-Creation**
   - [ ] Settings → Courier API → Check "Auto-create expeditions"
   - [ ] Save settings

2. **Test Order Completion**
   - [ ] Create new test order (different from manual test)
   - [ ] Change order status to "Completed" 
   - [ ] Should automatically trigger expedition creation
   - [ ] Check order for expedition data
   - [ ] Verify order notes contain expedition info

### Error Handling Testing
1. **Invalid API Credentials**
   - [ ] Enter wrong API token in settings
   - [ ] Try to create expedition → should show authentication error
   - [ ] Check WordPress error logs for proper logging

2. **Missing Required Data**
   - [ ] Create order with missing phone/address fields
   - [ ] Try expedition creation → should show validation error

3. **API Unavailable**
   - [ ] Stop your API server
   - [ ] Try expedition creation → should show connection error
   - [ ] Restart API and try again → should work

## Integration Testing

### WooCommerce Compatibility
- [ ] Plugin works with different order statuses
- [ ] Compatible with various WooCommerce themes
- [ ] Meta box appears correctly in order admin
- [ ] No conflicts with other WooCommerce plugins

### WordPress Compatibility  
- [ ] Plugin settings page renders correctly
- [ ] No JavaScript console errors
- [ ] AJAX requests work properly
- [ ] Nonce security working correctly

## Performance Testing

### Load Testing
- [ ] Create 10+ orders rapidly
- [ ] Enable auto-expedition creation
- [ ] Mark orders as completed in bulk
- [ ] Verify all expeditions created successfully
- [ ] Check for memory issues or timeouts

### API Response Times
- [ ] Monitor expedition creation time (should be < 30 seconds)
- [ ] Check for proper timeout handling
- [ ] Verify queue processing if using background jobs

## Browser Testing

### Supported Browsers
- [ ] Chrome/Chromium (latest)
- [ ] Firefox (latest)  
- [ ] Safari (if on macOS)
- [ ] Edge (latest)

### Responsive Testing
- [ ] Plugin settings page on mobile
- [ ] Order admin meta box on tablet
- [ ] AJAX functionality on different screen sizes

## Security Testing

### Input Validation
- [ ] SQL injection protection (WordPress handles this)
- [ ] XSS prevention in displayed data
- [ ] Nonce verification for AJAX requests
- [ ] Proper data sanitization in settings

### Permission Checks
- [ ] Only admins can access plugin settings
- [ ] Only users with order edit permissions can create expeditions
- [ ] API credentials not exposed in frontend

## Error Scenarios

### Common Issues to Test
1. **Network Issues**
   - [ ] Slow API responses (timeout testing)
   - [ ] Intermittent connectivity
   - [ ] DNS resolution failures

2. **Data Issues** 
   - [ ] Invalid product weights (negative, zero)
   - [ ] Missing product dimensions
   - [ ] Invalid address formats
   - [ ] Special characters in names/addresses

3. **API Errors**
   - [ ] Quote request creation failures
   - [ ] No available courier quotes
   - [ ] Expedition creation failures
   - [ ] AWB generation timeouts

## Debugging Information

### Enable WordPress Debug Logging
Add to `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### Check Log Files
- WordPress: `/wp-content/debug.log`
- API Server: Console output from `npm run start:web:dev`
- Database queries: Enable Prisma debug logging

### Test Data Cleanup
After testing:
- [ ] Remove test orders
- [ ] Clear error logs  
- [ ] Reset plugin settings if needed
- [ ] Remove test expeditions from API database

## Production Readiness Checklist

Before deployment:
- [ ] All tests passing
- [ ] No PHP warnings/errors
- [ ] API credentials configured for production
- [ ] Error logging properly configured
- [ ] Plugin settings documented for team
- [ ] Backup procedures in place