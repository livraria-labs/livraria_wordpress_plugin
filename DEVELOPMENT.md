# Development Guide for Livraria WordPress Plugin

This guide covers everything you need to know to continue developing this plugin.

## 📁 Project Structure

```
livraria_wordpress_plugin/
├── courier-expedition-wp-plugin.php  # Main plugin file (entry point)
├── includes/                          # PHP classes (business logic)
│   ├── class-api-client.php          # Handles API communication
│   └── class-order-handler.php       # Handles WooCommerce orders
├── assets/                            # Frontend files
│   ├── admin.css                     # Admin page styles
│   └── admin.js                      # Admin page JavaScript
├── build/                             # Generated files (DO NOT EDIT - auto-generated)
├── .gitignore                        # Files Git should ignore
├── .editorconfig                     # Code style settings
├── deploy.sh                         # Deployment script
├── README.md                         # User documentation
├── CHANGELOG.md                      # Version history
├── LICENSE                           # GPL license
└── DEVELOPMENT.md                    # This file
```

## 🔑 Key Concepts

### 1. WordPress Plugin Basics

**Main Plugin File** (`courier-expedition-wp-plugin.php`):
- This is the entry point WordPress loads
- Contains the main `LivrariaPlugin` class
- Hooks into WordPress using `add_action()` and `add_filter()`

**WordPress Hooks** (you'll see these everywhere):
- `add_action('hook_name', 'function_name')` - Runs code at specific times
- `add_filter('filter_name', 'function_name')` - Modifies data before it's used

**Common Hooks You're Using**:
- `init` - When WordPress initializes
- `admin_menu` - When admin menu is built
- `woocommerce_order_status_completed` - When order is completed
- `wp_ajax_*` - For AJAX requests from frontend

### 2. Class Structure

**LivrariaPlugin** (main file):
- Manages plugin lifecycle
- Handles WordPress hooks
- Connects everything together

**Livraria_API_Client** (`includes/class-api-client.php`):
- All API communication
- Login/authentication
- Making HTTP requests to your courier API

**Livraria_Order_Handler** (`includes/class-order-handler.php`):
- WooCommerce order processing
- Creating expeditions from orders
- Validating order data

### 3. How Data Flows

```
User completes order
    ↓
WooCommerce triggers: woocommerce_order_status_completed
    ↓
LivrariaPlugin::auto_create_expedition()
    ↓
Livraria_Order_Handler::create_expedition_for_order()
    ↓
Livraria_API_Client makes API calls
    ↓
Expedition created, data saved to order
```

## 🛠️ Development Workflow

### ⚡ Continuous Development Setup (IMPORTANT!)

**Problem**: If you upload a ZIP file, changes to your source files won't appear in WordPress.

**Solution**: Set up continuous development so your source files are directly used by WordPress.

#### Method 1: Symlink (Recommended - Best for Development)

A symlink makes WordPress use your source files directly. Changes appear immediately!

**Setup (one-time)**:
```bash
./dev-setup.sh /path/to/wordpress/wp-content/plugins
```

**Example**:
```bash
./dev-setup.sh ~/Sites/my-wordpress-site/wp-content/plugins
```

**What it does**:
- Creates a symlink from WordPress plugins directory to your source directory
- WordPress loads files directly from your development folder
- **Changes appear immediately** - just refresh the WordPress page!

**To remove later**:
```bash
rm /path/to/wordpress/wp-content/plugins/livraria
```

#### Method 2: File Sync (Alternative)

If symlinks don't work, use the sync script to copy files:

**Every time you make changes**:
```bash
./dev-sync.sh /path/to/wordpress/wp-content/plugins
```

**What it does**:
- Copies your source files to WordPress plugins directory
- Excludes build files, git files, etc.
- Run this whenever you make changes

**Auto-sync with file watcher** (optional):
```bash
# Install fswatch (macOS)
brew install fswatch

# Watch for changes and auto-sync
fswatch -o . | xargs -n1 -I{} ./dev-sync.sh /path/to/wordpress/wp-content/plugins
```

### Daily Development Cycle

1. **Set up continuous development** (one-time)
   ```bash
   ./dev-setup.sh /path/to/wordpress/wp-content/plugins
   ```

2. **Make Changes**
   - Edit PHP files in root or `includes/`
   - Edit CSS in `assets/admin.css`
   - Edit JavaScript in `assets/admin.js`
   - **Changes appear immediately** (refresh WordPress page)

3. **Test Locally**
   - Refresh WordPress admin page
   - Test the functionality
   - Check WordPress debug log for errors

4. **Commit Changes**
   ```bash
   git add .
   git commit -m "Description of what you changed"
   git push
   ```

5. **Deploy** (when ready for production)
   ```bash
   ./deploy.sh staging    # Test on staging
   ./deploy.sh production # Deploy to production
   ```

### Development vs Production Workflow

**During Development** (use symlink or sync):
- ✅ Edit source files → Changes appear immediately
- ✅ Fast iteration
- ✅ No need to create ZIP files
- ✅ Use `dev-setup.sh` or `dev-sync.sh`

**For Production Deployment** (use deploy script):
- ✅ Create clean, versioned ZIP file
- ✅ Test on staging first
- ✅ Deploy to production
- ✅ Use `deploy.sh staging` or `deploy.sh production`

**Key Difference**:
- **Development**: WordPress uses your source files directly
- **Production**: WordPress uses a packaged ZIP file

### Version Management

**When to Update Version**:
- After adding new features
- After fixing bugs
- Before deploying

**How to Update Version**:
1. Edit `courier-expedition-wp-plugin.php` line 5: `Version: 1.0.0` → `Version: 1.0.1`
2. Update `CHANGELOG.md` with what changed
3. Commit the changes

## 📝 Common Development Tasks

### Adding a New Feature

**Example: Add email notification when expedition is created**

1. **Add the method** in `LivrariaPlugin` class:
```php
public function send_expedition_email($order_id, $expedition_id) {
    $order = wc_get_order($order_id);
    $email = $order->get_billing_email();
    wp_mail($email, 'Expedition Created', 'Your expedition ID: ' . $expedition_id);
}
```

2. **Hook it in** the constructor:
```php
add_action('livraria_expedition_created', array($this, 'send_expedition_email'), 10, 2);
```

3. **Trigger it** where expeditions are created (in `class-order-handler.php`):
```php
do_action('livraria_expedition_created', $order_id, $expedition_id);
```

### Modifying API Calls

**Location**: `includes/class-api-client.php`

**Key Methods**:
- `login()` - Authenticates with API
- `get_couriers()` - Gets available couriers
- `create_quote_request()` - Creates quote
- `create_expedition()` - Creates expedition

**To modify an API call**:
1. Find the method in `class-api-client.php`
2. Modify the endpoint URL or request data
3. Test with the API test button in WordPress admin

### Changing Admin UI

**CSS**: Edit `assets/admin.css`
- Styles for the settings page
- Styles for the expedition metabox

**JavaScript**: Edit `assets/admin.js`
- AJAX calls
- Form interactions
- Dynamic UI updates

**Settings Page**: Edit `admin_page()` method in main plugin file
- Add new form fields
- Modify existing fields

### Adding New Settings

1. **Register the setting** in `admin_init()`:
```php
register_setting('courier_api_settings', 'courier_new_setting');
```

2. **Add form field** in `admin_page()`:
```php
<tr>
    <th scope="row">New Setting</th>
    <td><input type="text" name="courier_new_setting" value="<?php echo esc_attr(get_option('courier_new_setting')); ?>" /></td>
</tr>
```

3. **Use it** anywhere:
```php
$value = get_option('courier_new_setting', 'default');
```

## 🐛 Debugging

### Enable WordPress Debug Mode

Add to `wp-config.php` (on your WordPress site):
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

**Logs go to**: `/wp-content/debug.log`

### Your Plugin's Debug Logging

The plugin already logs important events:
- API requests/responses
- Errors
- Screen information

**Check logs**:
```bash
tail -f /path/to/wordpress/wp-content/debug.log
```

### Common Issues

**"WooCommerce not found"**:
- WooCommerce plugin not activated
- Check: `class_exists('WooCommerce')`

**"API connection failed"**:
- Check API URL in settings
- Check credentials
- Use "Test API Connection" button

**"Expedition already exists"**:
- Order already has an expedition
- Check order meta: `_courier_expedition_id`

## 🔄 Git Workflow (Version Control)

### Basic Commands

```bash
# See what files changed
git status

# Stage files for commit
git add filename.php
# Or stage everything
git add .

# Commit changes
git commit -m "Fixed bug in expedition creation"

# Push to remote repository
git push

# Pull latest changes
git pull
```

### Good Commit Messages

**Format**: `Action: Brief description`

Examples:
- `Fix: Corrected API endpoint URL`
- `Add: Email notification for expeditions`
- `Update: Improved error handling`
- `Refactor: Cleaned up order validation code`

### Branching (Advanced - Optional)

For bigger features:
```bash
# Create feature branch
git checkout -b feature/email-notifications

# Make changes, commit
git add .
git commit -m "Add email notifications"

# Merge back to main
git checkout main
git merge feature/email-notifications
```

## 🚀 Deployment

### Using deploy.sh

**Staging** (test environment):
```bash
./deploy.sh staging
```
- Creates ZIP file: `build/livraria-v1.0.0-staging.zip`
- Upload manually to staging WordPress site

**Production** (live site):
```bash
./deploy.sh production
```
- Creates ZIP file: `build/livraria-v1.0.0-production.zip`
- Upload manually to production WordPress site

### Manual Deployment

1. Run deploy script
2. Upload ZIP to WordPress
3. Go to Plugins → Add New → Upload Plugin
4. Upload the ZIP file
5. Activate plugin

## 📋 Testing Checklist

Before deploying, test:

- [ ] Plugin activates without errors
- [ ] Settings page loads and saves correctly
- [ ] API connection test works
- [ ] Create expedition manually from order page
- [ ] Auto-create expedition works (complete an order)
- [ ] AWB number appears after creation
- [ ] Tracking URL works
- [ ] No PHP errors in debug log
- [ ] No JavaScript errors in browser console

## 🎯 Next Steps for Development

### Immediate Priorities

1. **Update Author Info**
   - Line 6 in main plugin file: `Author: Your Company` → `Author: Livraria S.R.L.`

2. **Test Everything**
   - Set up local WordPress with WooCommerce
   - Test all features end-to-end

3. **Document API**
   - Document your actual API endpoints
   - Update README with real examples

### Future Enhancements

- **Error Handling**: Better user-facing error messages
- **Logging**: More detailed logging for troubleshooting
- **Bulk Operations**: Create expeditions for multiple orders
- **Courier Selection**: Let users choose courier from quotes
- **Webhooks**: Listen for expedition status updates from API

## 📚 Essential Resources

**WordPress Codex**: https://codex.wordpress.org/
- Plugin development guide
- Function reference

**WooCommerce Docs**: https://woocommerce.com/document/
- Order handling
- Hooks and filters

**PHP Manual**: https://www.php.net/manual/
- PHP syntax and functions

## ⚠️ Important Rules

1. **Never edit files in `build/`** - They're auto-generated
2. **Always test locally first** - Don't deploy untested code
3. **Update version number** - Before each deployment
4. **Update CHANGELOG.md** - Document all changes
5. **Commit often** - Small, frequent commits are better
6. **Write clear commit messages** - Future you will thank you

## 🆘 Getting Help

**When stuck**:
1. Check WordPress debug log
2. Check browser console (F12) for JavaScript errors
3. Search WordPress Codex for the function/hook you're using
4. Check if WooCommerce is active and up to date

**Common Questions**:
- "Where do I add X?" → Usually in the main plugin file or appropriate class
- "How do I save data?" → Use `update_option()` for settings, `update_post_meta()` for order data
- "How do I get order data?" → `wc_get_order($order_id)` then use order methods

---

**Remember**: Start small, test often, commit frequently. You've got this! 🚀

