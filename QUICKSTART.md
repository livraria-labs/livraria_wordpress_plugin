# Quick Start: Continuous Development Setup

## The Problem

You uploaded a ZIP file to WordPress. Now when you edit source files, changes don't appear because WordPress is using the uploaded files, not your source files.

## The Solution

Set up continuous development so WordPress uses your source files directly.

## One-Time Setup (Choose One Method)

### Option 1: Symlink (Recommended) ⭐

**Best for**: Local development, fastest workflow

```bash
./dev-setup.sh /path/to/wordpress/wp-content/plugins
```

**Example**:
```bash
./dev-setup.sh ~/Sites/my-wordpress-site/wp-content/plugins
```

**What happens**:
- Creates a link from WordPress to your source files
- WordPress loads files directly from your development folder
- **Changes appear immediately** - just refresh the page!

### Option 2: File Sync

**Best for**: When symlinks don't work, or you prefer copying files

```bash
./dev-sync.sh /path/to/wordpress/wp-content/plugins
```

**What happens**:
- Copies your source files to WordPress
- Run this script whenever you make changes
- Or set up auto-sync (see DEVELOPMENT.md)

## After Setup

1. **Go to WordPress Admin → Plugins**
2. **Activate "Livraria" plugin** (if not already activated)
3. **Start editing files** in your source directory
4. **Refresh WordPress page** - changes appear immediately!

## Daily Workflow

1. Edit files in your source directory
2. Refresh WordPress page to see changes
3. Test functionality
4. Commit changes to git when ready

## When Ready for Production

Use the deploy script to create a ZIP file:

```bash
./deploy.sh staging    # For testing
./deploy.sh production # For live site
```

Then upload the ZIP file from `build/` directory.

## Troubleshooting

**"Plugin not found"**:
- Make sure you provided the correct path to `wp-content/plugins`
- Check that the symlink was created: `ls -la /path/to/wordpress/wp-content/plugins/livraria`

**"Changes not appearing"**:
- Make sure you're editing files in your source directory, not in WordPress
- Try clearing WordPress cache (if using caching plugin)
- Hard refresh browser (Cmd+Shift+R on Mac, Ctrl+Shift+R on Windows)

**"Symlink doesn't work"**:
- Use the file sync method instead (`dev-sync.sh`)
- Some hosting environments don't support symlinks

## Need More Help?

See `DEVELOPMENT.md` for complete documentation.

