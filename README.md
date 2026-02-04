# Parish Document Display WordPress Plugin

A WordPress plugin that displays directory contents as a table using a shortcode. Supports recursive folder navigation, sub-documents, and markdown commentary.

## Features

- Display files from any server directory as a formatted table
- **Recursive navigation** - Browse subfolders with breadcrumb navigation
- **Virtual "Current" folder** - Show the latest documents across all subfolders
- **Commentary.md** - Display markdown content above file listings
- **Annex folders** - Automatically display related sub-documents inline
- **Meta descriptions** - Add descriptions to files via text files
- PDF files open in new tabs, all files have download buttons

## Installation

1. Copy the `docdisplay` folder to your WordPress `wp-content/plugins/` directory
2. Log in to WordPress admin
3. Go to **Plugins** and activate **Parish Document Display**
4. Go to **Settings → Parish Document Display** to configure the base directory path

## Configuration

Set the **Base Directory Path** to the absolute server path where your documents are stored.

Example: `/var/www/html/wp-content/uploads/documents`

The path must:
- Be an absolute server path (not a URL)
- Exist and be readable by the web server
- Be accessible via the web (within your site's document root)

## Usage

### Basic Usage

```
[docdisplay path="subfolder"]
```

The `path` attribute is relative to the base directory configured in settings.

### Recursive Mode

Enable folder navigation with breadcrumbs:

```
[docdisplay path="documents" recursive="true"]
```

When enabled:
- Subfolders appear as clickable buttons above the file table
- Breadcrumb navigation shows the current path
- Click any breadcrumb segment to navigate back

### Virtual "Current" Folder

Show the latest documents from all subfolders in one view:

```
[docdisplay path="documents" recursive="true" show_current="true"]
```

When enabled:
- A "Current" button appears at the top of the subfolder list
- Clicking it shows the most recent documents across all subfolders
- Documents are sorted by modification date (newest first)
- Use `limit` to control how many documents are shown (default: 10)

```
[docdisplay path="documents" recursive="true" show_current="true" limit="20"]
```

The `?docdisplay_path=current` URL always works, even if `show_current` is not enabled.

### Filtering by Extension

Show only files with a specific extension:

```
[docdisplay path="documents" include="pdf"]
```

Hide files with specific extensions (comma-separated):

```
[docdisplay path="documents" exclude="doc,docx,odt"]
```

Hide specific extensions from displayed filenames:

```
[docdisplay path="minutes" hide_extension="pdf,docx"]
```

This removes the extension from the displayed name while keeping the actual file intact.

### Sorting Options

Sort directories ascending or descending (default: desc):

```
[docdisplay path="documents" recursive="true" directory_sort="asc"]
```

Sort files ascending or descending (default: desc):

```
[docdisplay path="documents" file_sort="asc"]
```

### Display Options

Show breadcrumb navigation:

```
[docdisplay path="documents" recursive="true" show_title="true"]
```

Show "No documents found" message when folder is empty:

```
[docdisplay path="documents" show_empty="true"]
```

Show empty directories in folder list (default: hidden):

```
[docdisplay path="documents" recursive="true" hide_empty_dirs="false"]
```

### All Attributes

| Attribute | Values | Default | Description |
|-----------|--------|---------|-------------|
| `path` | string | `""` | Relative path from base directory |
| `recursive` | true/false | false | Enable subfolder navigation |
| `show_current` | true/false | false | Show "Current" button for latest docs |
| `limit` | number | 10 | Number of documents in Current view |
| `include` | extension | `""` | Only show files with this extension |
| `exclude` | ext,ext,... | `""` | Hide files with these extensions |
| `hide_extension` | ext,ext,... | `""` | Hide extensions from display names |
| `show_title` | true/false | false | Show breadcrumb navigation |
| `show_empty` | true/false | false | Show message when no files found |
| `directory_sort` | asc/desc | desc | Sort order for directories |
| `file_sort` | asc/desc | desc | Sort order for files |
| `hide_empty_dirs` | true/false | true | Hide empty directories |

### Examples

If your base path is `/var/www/html/documents`:

| Shortcode | Displays |
|-----------|----------|
| `[docdisplay path=""]` | All files in base directory |
| `[docdisplay path="meetings"]` | Files in meetings folder |
| `[docdisplay path="policies" recursive="true"]` | Policies with subfolder navigation |
| `[docdisplay path="minutes" recursive="true" show_current="true"]` | Minutes with "Current" button |
| `[docdisplay path="minutes" hide_extension="pdf"]` | PDFs displayed without .pdf extension |
| `[docdisplay path="reports" exclude="doc,docx,odt"]` | All files except Word/ODT |

## Output

The plugin displays a table with the following columns:

| Column | Description |
|--------|-------------|
| **File Name** | The filename. PDFs are clickable links that open in a new browser tab |
| **Document Date** | The file's modification date |
| **Download** | A download button that forces the file to download |
| **Description** | Contents of the meta file (only shown if any meta files exist) |

## Special Features

### Commentary.md

If a folder contains a file named `Commentary.md`, its content is displayed above the file table. Supports basic markdown:

- Headers (`#`, `##`, `###`)
- Bold (`**text**`) and italic (`*text*`)
- Links (`[text](url)`)
- Unordered lists (`- item`)

### Annex Folders (Sub-documents)

Create a folder named `{filename}_annexes` to add related documents that display inline below the main document.

**Example:**
```
documents/
  annual-report.pdf
  annual-report.pdf_annexes/    <- Annex folder (filename + _annexes)
    appendix-a.pdf              <- Displayed inline below annual-report.pdf
    appendix-b.pdf
```

The annex files appear in a compact row with download icons, making it easy to access related documents. Annexes are also shown in the "Current" view.

### Meta Files

To add a description for a file, create a text file named `meta_{filename}.txt` in the same directory.

**Example:**

For a file called `annual-report.pdf`, create `meta_annual-report.pdf.txt` containing:

```
Annual financial report for 2024
```

This text will appear in the Description column.

## What Gets Displayed

The plugin shows:
- PDF files (clickable links)
- Other document files (DOC, DOCX, XLS, etc.)
- Subfolders (when `recursive="true"`)

The plugin hides:
- Annex folders (`*_annexes`) - displayed inline with their parent document
- Files starting with `meta_`
- `Commentary.md` files (rendered above the table)
- Hidden files (starting with `.`)

## Reverse Proxy Configuration

If your documents are stored outside the WordPress directory (e.g., mounted from another service like Nextcloud), you need to configure your reverse proxy to serve the files.

### Docker Mount

First, mount the document directory into the WordPress container (read-only):

```yaml
# docker-compose.yml
services:
  wordpress:
    volumes:
      - /path/to/documents:/var/www/html/wp-content/uploads/public-docs:ro
```

### Caddy

Add a `handle_path` block to serve files from the external location:

```
example.com {
    # Serve documents from external path
    handle_path /wp-content/uploads/public-docs/* {
        root * /path/to/documents
        file_server
    }

    # ... rest of WordPress config
    php_fastcgi localhost:9000 {
        root /var/www/html
    }
    root * /path/to/wordpress
    file_server
}
```

**Caddy permissions:** If your documents are outside the web root (e.g., Nextcloud data), Caddy needs read access:

```bash
# Add caddy to the file owner's group (e.g., www-data)
usermod -aG www-data caddy

# Ensure parent directories allow traversal (x permission)
chmod o+x /path/to/parent/directories

# Restart Caddy to pick up group membership
systemctl restart caddy
```

### Nginx

Add a location block:

```nginx
server {
    # Serve documents from external path
    location /wp-content/uploads/public-docs/ {
        alias /path/to/documents/;
        autoindex off;
    }

    # ... rest of WordPress config
}
```

After configuration changes:
- Caddy: `systemctl reload caddy`
- Nginx: `systemctl reload nginx`

## Troubleshooting

**"Base path not configured"**
Go to Settings → Parish Document Display and set the base directory path.

**"Directory not found or not readable"**
- Check the path exists on the server
- Ensure the web server has read permissions for the directory

**"Base directory not found or not readable"**
- This appears when accessing `?docdisplay_path=current`
- Verify the shortcode path exists and is readable

**Download links don't work**
- Ensure the base directory is within your web root
- Check that files are accessible via the web server

**Subfolders not appearing**
- Ensure you're using `recursive="true"` in the shortcode
- Check that the folders are not named `{filename}_annexes` (those are treated as annex folders)
- Empty folders are hidden by default; use `hide_empty_dirs="false"` to show them

**"Current" button not showing**
- Ensure you're using `show_current="true"` in the shortcode
- The button only appears at the root level, not in subfolders

## Security

- Path traversal attacks are blocked (no `..` allowed in paths)
- URL parameters are sanitized to prevent injection
- All output is escaped to prevent XSS
- Admin settings use WordPress nonces

## Automatic Updates

The plugin checks GitHub for new releases and shows update notifications in the WordPress admin. Updates can be installed with one click from the Plugins page.

To create a new release:

1. Update the version in `docdisplay.php` (both the header and `DOCDISPLAY_VERSION` constant)
2. Commit and push changes
3. Create a new release on GitHub with a tag (e.g., `v1.8.0`)
4. WordPress sites will detect the update within 12 hours (or immediately if they check for updates)

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher

## Changelog

### 1.8.0
- Added virtual "Current" folder showing latest documents across all subfolders
- Added `show_current` attribute to show/hide the Current button (default: false)
- Added `limit` attribute to control number of documents in Current view (default: 10)
- Annexes now display in Current view
- Renamed "Published" column to "Document Date"

### 1.7.4
- Added `show_title` attribute for breadcrumb navigation
- Added `show_empty` attribute to show message when no files found
- Added `directory_sort` attribute (asc/desc)
- Added `hide_empty_dirs` attribute to show/hide empty directories
- Added `file_sort` attribute (asc/desc)

### 1.6.3
- Changed `hide_extension` to accept comma-separated list of extensions to hide from display

### 1.6.2
- Support multiple extensions in exclude attribute (comma-separated)
- Display annex files one per line

### 1.6.0
- Added `exclude="ext"` attribute to hide files with a specific extension

### 1.5.0
- Added `include="ext"` attribute to filter files by extension
- Added `hide_extension="true"` attribute to hide extensions in display
- Added automatic updates from GitHub releases

### 1.4.0
- Fixed Commentary.md rendering (replaced wpautop with custom parser)
- Subfolders now display as buttons above the file table (not inside it)
- Changed annex folder naming to `{filename}_annexes` to avoid conflicts

### 1.3.0
- Added `recursive="true"` attribute for subfolder navigation
- Added breadcrumb navigation
- Added Commentary.md rendering (basic markdown support)
- Improved path sanitization for subpaths

### 1.2.0
- Added annex folder support (sub-documents displayed inline)

### 1.1.0
- Initial release with file table display and meta descriptions

## License

Creative Commons Attribution-NonCommercial 4.0 International (CC BY-NC 4.0)

Free to use and modify for non-commercial purposes. See [LICENSE](LICENSE) for details.
