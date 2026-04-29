# Spr.ru 🕷️
[Russian version of this documentation is available here](README_RU.md)

A PHP-based script for automated data extraction from the spr.ru portal. It supports multi-level category structures (L1-L3) and complete business contact harvesting.

## 🚀 Features

*   **🏗️ Auto Database Setup:** On first run, the script automatically creates all necessary tables.
*   **💾 Smart Caching:** Pages are stored in the database (`page_cache`) to avoid redundant requests.
*   **📝 Logging:** The process is recorded in the `parser_logs` table. **Important:** Logs are cleared and refreshed on each new run.
*   **🛡️ Anti-Ban Protection:** Uses delays between requests (`Crawl-delay`) and proxy support.
*   **🔄 AJAX Processing:** Automatically expands and parses hidden L3 categories behind the "More" button, as well as handles dynamic company loading via AJAX pagination.
*   **Hidden L3 Categories:** Automatically expands and parses categories hidden behind the "More" button (dynamic loading via AJAX).
*   **Infinite Pagination:** Handles AJAX-based company loading when scrolling or clicking the "Load more" button.

## 🛠️ Configuration

1. **Database:** Create an empty MySQL database (e.g., `spr_parser`).
2. **Configuration:** In the `index.php` file (lines 24-28), specify the connection parameters for your database server:

   ```php
   private $dbName = 'your_database_name';
   private $dbHost = 'database_host';
   private $dbUser = 'username';
   private $dbPass = 'password';
   ```

## ⚙️ Installation & Running

This project is a CLI tool. Running via terminal ensures stable operation without the time limits (timeouts) found in browsers.

1. **Clone the repository**:
   ```bash
   git clone https://github.com
   cd spr-content-parser
   ```

2. **Install dependencies** (DiDom library, etc.):
   ```bash
   composer install
   ```

3. **Configure database connection** in the `index.php` file (configuration section).

4. **Run the parser**:
   ```bash
   php index.php
   ```
   
## 📂 What the parser collects

- Company names, addresses, and phones
- Number of reviews (positive/negative)
- Category hierarchy (L1 → L2 → L3)
- Phone images: Saved to the `company_images` folder, organized into nested category folders like L1/L2/L3/Company_Name_ID/

## 🔧 Technical details

- Category structure: The parser recursively traverses all available L1, L2, and expanded L3 categories.
- Selectors: Adapted to the current DOM structure of the site.

### 📂 Structured data storage

The script automatically distributes images and contact data into nested folders corresponding to the site's categories.
* **Protected data extraction:** Processes and saves contact phone numbers presented in SVG format.

## 🖼️ Interface & Data Structure

### 📊 Database Storage

Demonstration of table structure and collected data. Normalization and relationship preservation between categories (L1-L3) is implemented.
![Database Structure](screenshots/database_structure.png)

### 📂 File System & Anti-Ban Bypass

Example of automatic data distribution into nested folders and successful extraction of contact numbers hidden behind SVG images.
![File System Logic](screenshots/file_system_logic.png)

License: MIT
