<?php
// DB Connection
$host = 'localhost';
$dbname = 'shop_db';
$user = 'root';
$pass = '';
try {
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname`");
    $pdo->exec("USE `$dbname`");

    // Create tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE,
        password VARCHAR(255)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100),
        quantity INT,
        price DECIMAL(10,2)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        customer_id INT AUTO_INCREMENT PRIMARY KEY,
        customer_name VARCHAR(100) UNIQUE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
        invoice_id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT,
        seller VARCHAR(100),
        total_amount DECIMAL(10,2),
        date DATETIME,
        FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT,
        item_name VARCHAR(100),
        quantity INT,
        sell_price DECIMAL(10,2),
        total DECIMAL(10,2),
        FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id)
    )");

    // Seed default users
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO users (username, password) VALUES
            ('admin', 'admin123'), ('Attiqullah', 'attiq123'), ('javad', 'javad123'), ('Noorgul', 'noorgul')");
    }
} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

session_start();
$login_error = '';
$delete_error = '';
$search_error = '';
$change_user_error = '';
$change_user_success = '';

// Handle Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username=? AND password=?");
    $stmt->execute([$_POST['username'], $_POST['password']]);
    if ($stmt->fetch()) {
        $_SESSION['logged_in'] = true;
        $_SESSION['username'] = $_POST['username'];
    } else {
        $login_error = "Invalid username or password.";
    }
}

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Handle View Invoice Details
$invoice_details = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['view_invoice'])) {
    $invoice_id = intval($_POST['view_invoice']);
    $stmt = $pdo->prepare("
        SELECT i.*, c.customer_name 
        FROM invoices i
        JOIN customers c ON i.customer_id = c.customer_id
        WHERE i.invoice_id = ?
    ");
    $stmt->execute([$invoice_id]);
    $invoice_details = $stmt->fetch();
    
    if ($invoice_details) {
        $stmt = $pdo->prepare("SELECT * FROM sales WHERE invoice_id=?");
        $stmt->execute([$invoice_id]);
        $invoice_details['items'] = $stmt->fetchAll();
    }
}

// Fetch all users for delete and change user forms
$users = $pdo->query("SELECT username FROM users ORDER BY username")->fetchAll();

if (!empty($_SESSION['logged_in'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Add items
        if (isset($_POST['action']) && $_POST['action'] === 'add') {
            $name = $_POST['name'];
            $qty = intval($_POST['quantity']);
            $price = floatval($_POST['price']);
            $stmt = $pdo->prepare("SELECT * FROM inventory WHERE name=?");
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                $pdo->prepare("UPDATE inventory SET quantity=quantity+?, price=? WHERE name=?")
                    ->execute([$qty, $price, $name]);
            } else {
                $pdo->prepare("INSERT INTO inventory (name, quantity, price) VALUES (?, ?, ?)")
                    ->execute([$name, $qty, $price]);
            }
        }

        // Add new user
        if (isset($_POST['action']) && $_POST['action'] === 'add_user') {
            $username = trim($_POST['username']);
            $password = trim($_POST['password']);
            
            // Check if username already exists
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error_message = "Username '$username' already exists.";
            } elseif (empty($username) || empty($password)) {
                $error_message = "Username and password are required.";
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
                    $stmt->execute([$username, $password]);
                    $success_message = "User '$username' added successfully!";
                    $users = $pdo->query("SELECT username FROM users ORDER BY username")->fetchAll();
                } catch (PDOException $e) {
                    $error_message = "Error adding user: " . $e->getMessage();
                }
            }
        }

        // Delete user
        if (isset($_POST['action']) && $_POST['action'] === 'delete_user') {
            $username_to_delete = trim($_POST['username_to_delete']);
            if (empty($username_to_delete)) {
                $error_message = "Please select a user to delete.";
            } elseif ($username_to_delete === 'Attiqullah') {
                $error_message = "Cannot delete the Attiqullah user.";
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
                    $stmt->execute([$username_to_delete]);
                    if ($stmt->fetch()) {
                        $pdo->prepare("DELETE FROM users WHERE username = ?")
                            ->execute([$username_to_delete]);
                        $success_message = "User '$username_to_delete' deleted successfully!";
                        $users = $pdo->query("SELECT username FROM users ORDER BY username")->fetchAll();
                    } else {
                        $error_message = "User '$username_to_delete' not found.";
                    }
                } catch (PDOException $e) {
                    $error_message = "Error deleting user: " . $e->getMessage();
                }
            }
        }

        // Change user details
        if (isset($_POST['action']) && $_POST['action'] === 'change_user') {
            $username_to_change = trim($_POST['username_to_change']);
            $new_username = trim($_POST['new_username']);
            $new_password = trim($_POST['new_password']);
            $admin_password = trim($_POST['admin_password']);
            
            if (empty($username_to_change) || empty($new_username) || empty($new_password) || empty($admin_password)) {
                $change_user_error = "All fields are required.";
            } elseif ($username_to_change === 'Attiqullah') {
                $change_user_error = "Cannot change details for the Attiqullah user.";
            } else {
                // Verify admin password
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND password = ?");
                $stmt->execute(['Attiqullah', $admin_password]);
                if (!$stmt->fetch()) {
                    $change_user_error = "Incorrect admin password.";
                } else {
                    try {
                        // Check if new username already exists
                        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND username != ?");
                        $stmt->execute([$new_username, $username_to_change]);
                        if ($stmt->fetch()) {
                            $change_user_error = "New username '$new_username' already exists.";
                        } else {
                            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
                            $stmt->execute([$username_to_change]);
                            if ($stmt->fetch()) {
                                $pdo->prepare("UPDATE users SET username = ?, password = ? WHERE username = ?")
                                    ->execute([$new_username, $new_password, $username_to_change]);
                                $change_user_success = "User details for '$username_to_change' updated successfully!";
                                $users = $pdo->query("SELECT username FROM users ORDER BY username")->fetchAll();
                            } else {
                                $change_user_error = "User '$username_to_change' not found.";
                            }
                        }
                    } catch (PDOException $e) {
                        $change_user_error = "Error changing user details: " . $e->getMessage();
                    }
                }
            }
        }

        // Process multi-item sales
        if (isset($_POST['action']) && $_POST['action'] === 'sell_multi') {
            $customer_name = $_POST['customer_name'];
            $seller = $_SESSION['username'];
            $items = isset($_POST['items']) ? $_POST['items'] : [];
            $validation_error = false;

            if (empty($items) || !is_array($items)) {
                $error_message = "No items selected for sale.";
                $validation_error = true;
            } else {
                $stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_name=?");
                $stmt->execute([$customer_name]);
                $cust = $stmt->fetchColumn();
                if (!$cust) {
                    $pdo->prepare("INSERT INTO customers (customer_name) VALUES (?)")->execute([$customer_name]);
                    $cust = $pdo->lastInsertId();
                }

                $valid_items = [];
                $total_amount = 0;
                foreach ($items as $index => $item) {
                    if (empty($item['name']) || empty($item['quantity']) || empty($item['sell_price'])) {
                        continue;
                    }

                    $name = $item['name'];
                    $qty = intval($item['quantity']);
                    $sell_price = floatval($item['sell_price']);

                    if ($qty <= 0 || $sell_price <= 0) {
                        $error_message = "Quantity and sell price for '$name' must be greater than 0.";
                        $validation_error = true;
                        break;
                    }

                    $stmt = $pdo->prepare("SELECT * FROM inventory WHERE name=?");
                    $stmt->execute([$name]);
                    $inventory_item = $stmt->fetch();

                    if (!$inventory_item) {
                        $error_message = "Item '$name' not found in inventory.";
                        $validation_error = true;
                        break;
                    }

                    if ($inventory_item['quantity'] < $qty) {
                        $error_message = "Insufficient quantity for '$name'. Available: {$inventory_item['quantity']}, Requested: $qty";
                        $validation_error = true;
                        break;
                    }

                    $item_total = $qty * $sell_price;
                    $total_amount += $item_total;

                    $valid_items[] = [
                        'name' => $name,
                        'quantity' => $qty,
                        'sell_price' => $sell_price,
                        'total' => $item_total,
                        'inventory_item' => $inventory_item
                    ];
                }

                if (!$validation_error && empty($valid_items)) {
                    $error_message = "Please add at least one valid item to create an invoice.";
                    $validation_error = true;
                }

                if (!$validation_error && !empty($valid_items)) {
                    try {
                        $pdo->beginTransaction();

                        $stmt = $pdo->prepare("INSERT INTO invoices (customer_id, seller, total_amount, date) VALUES (?, ?, ?, NOW())");
                        $stmt->execute([$cust, $seller, $total_amount]);
                        $invoice_id = $pdo->lastInsertId();

                        foreach ($valid_items as $item) {
                            $new_qty = $item['inventory_item']['quantity'] - $item['quantity'];
                            if ($new_qty > 0) {
                                $pdo->prepare("UPDATE inventory SET quantity=? WHERE name=?")
                                    ->execute([$new_qty, $item['name']]);
                            } else {
                                $pdo->prepare("DELETE FROM inventory WHERE name=?")
                                    ->execute([$item['name']]);
                            }

                            $pdo->prepare("INSERT INTO sales (invoice_id, item_name, quantity, sell_price, total) VALUES (?, ?, ?, ?, ?)")
                                ->execute([$invoice_id, $item['name'], $item['quantity'], $item['sell_price'], $item['total']]);
                        }

                        $pdo->commit();
                        $success_message = "Invoice #$invoice_id created successfully! Total: $" . number_format($total_amount, 2);
                    } catch (Exception $e) {
                        $pdo->rollback();
                        $error_message = "Error processing sale: " . $e->getMessage();
                    }
                }
            }
        }

        // Delete invoice
        if (isset($_POST['delete_invoice'])) {
            $invoice_id = intval($_POST['delete_invoice']);
            $password = $_POST['delete_password'];
            $username = $_SESSION['username'];

            $stmt = $pdo->prepare("SELECT * FROM users WHERE username=? AND password=?");
            $stmt->execute([$username, $password]);
            if ($stmt->fetch()) {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("DELETE FROM sales WHERE invoice_id=?")->execute([$invoice_id]);
                    $pdo->prepare("DELETE FROM invoices WHERE invoice_id=?")->execute([$invoice_id]);
                    $pdo->commit();
                    $success_message = "Invoice #$invoice_id deleted successfully!";
                } catch (Exception $e) {
                    $pdo->rollback();
                    $delete_error = "Error deleting invoice: " . $e->getMessage();
                }
            } else {
                $delete_error = "Incorrect password. Invoice deletion failed.";
            }
        }

        // Handle search
        if (isset($_POST['search'])) {
            $search_term = trim($_POST['search_term']);
            if (!empty($search_term)) {
                $query = "
                    SELECT i.*, c.customer_name 
                    FROM invoices i
                    JOIN customers c ON i.customer_id = c.customer_id
                    WHERE c.customer_name LIKE ? OR i.invoice_id = ?
                    ORDER BY i.invoice_id DESC
                ";
                $stmt = $pdo->prepare($query);
                $search_param = "%$search_term%";
                $stmt->execute([$search_param, $search_term]);
                $invoices = $stmt->fetchAll();
                
                if (empty($invoices)) {
                    $search_error = "No invoices found for the given customer name or invoice ID.";
                }
            } else {
                $search_error = "Please enter a customer name or invoice ID to search.";
            }
        } else {
            $invoices = $pdo->query("
                SELECT i.*, c.customer_name 
                FROM invoices i
                JOIN customers c ON i.customer_id = c.customer_id
                ORDER BY i.invoice_id DESC
            ")->fetchAll();
        }
    } else {
        $invoices = $pdo->query("
            SELECT i.*, c.customer_name 
            FROM invoices i
            JOIN customers c ON i.customer_id = c.customer_id
            ORDER BY i.invoice_id DESC
        ")->fetchAll();
    }

    $inventory = $pdo->query("SELECT * FROM inventory ORDER BY name")->fetchAll();
    $last_invoice = $pdo->query("
        SELECT i.*, c.customer_name FROM invoices i
        JOIN customers c ON i.customer_id=c.customer_id
        ORDER BY i.invoice_id DESC LIMIT 1
    ")->fetch();

    if ($last_invoice) {
        $last_invoice_items = $pdo->prepare("SELECT * FROM sales WHERE invoice_id=?");
        $last_invoice_items->execute([$last_invoice['invoice_id']]);
        $last_invoice_items = $last_invoice_items->fetchAll();
    }

    // Calculate total sales
    $total_sales = $pdo->query("SELECT SUM(total_amount) as total_sales FROM invoices")->fetchColumn();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop System</title>
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --success-color: #2ecc71;
            --info-color: #1abc9c;
            --background-color: #ecf0f1;
            --card-bg: #ffffff;
            --text-color: #34495e;
            --border-radius: 8px;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, var(--background-color) 0%, #d5dbde 100%);
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .container {
            max-width: 1280px;
            width: 95%;
            margin: 1rem auto;
            padding: 1.5rem;
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .header span {
            font-size: 1.2rem;
            font-weight: 500;
            color: var(--primary-color);
        }

        h2 {
            color: var(--primary-color);
            margin-bottom: 1.2rem;
            font-size: 1.6rem;
            font-weight: 600;
            border-bottom: 2px solid var(--secondary-color);
            padding-bottom: 0.4rem;
        }

        input, select, button {
            padding: 0.6rem;
            margin: 0.4rem 0;
            border: 1px solid #dfe6e9;
            border-radius: 6px;
            font-size: 0.95rem;
            transition: var(--transition);
            max-width: 100%;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }

        button {
            background: var(--secondary-color);
            color: white;
            border: none;
            cursor: pointer;
            font-weight: 500;
            padding: 0.6rem 1.2rem;
            touch-action: manipulation;
        }

        button:hover {
            background: #2980b9;
            transform: translateY(-1px);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        th, td {
            padding: 0.8rem;
            text-align: left;
            border-bottom: 1px solid #dfe6e9;
            font-size: 0.9rem;
        }

        th {
            background: var(--primary-color);
            color: white;
            font-weight: 600;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .logout {
            background: var(--accent-color);
            padding: 0.5rem 1rem;
            text-decoration: none;
            border-radius: 6px;
            color: white;
            font-weight: 500;
            transition: var(--transition);
        }

        .logout:hover {
            background: #c0392b;
            transform: translateY(-1px);
        }

        #printBtn {
            background: var(--success-color);
        }

        #printBtn:hover {
            background: #27ae60;
        }

        .login-box {
            max-width: 400px;
            width: 90%;
            background: var(--card-bg);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            text-align: center;
            animation: fadeIn 0.5s ease;
            position: relative;
            overflow: hidden;
        }

        .login-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--secondary-color);
        }

        .login-box h2 {
            margin-bottom: 1.5rem;
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-color);
            border-bottom: none;
        }

        .login-box form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .login-box input {
            padding: 0.8rem;
            border: 1px solid #dfe6e9;
            border-radius: 6px;
            font-size: 0.95rem;
            transition: var(--transition);
            background: #f8f9fa;
        }

        .login-box input:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
            background: var(--card-bg);
        }

        .login-box button {
            background: var(--secondary-color);
            padding: 0.8rem;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 600;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .login-box button:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .login-box button::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.4s ease, height 0.4s ease;
        }

        .login-box button:hover::after {
            width: 200px;
            height: 200px;
        }

        .error {
            color: var(--accent-color);
            background: #ffe6e6;
            padding: 0.6rem;
            border-radius: 6px;
            margin-bottom: 0.8rem;
            font-size: 0.85rem;
            animation: slideIn 0.3s ease;
        }

        .success {
            color: var(--success-color);
            background: #e6f4ea;
            padding: 0.6rem;
            border-radius: 6px;
            margin-bottom: 0.8rem;
        }

        .item-row {
            display: flex;
            gap: 0.8rem;
            margin-bottom: 0.8rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .item-row select, .item-row input {
            flex: 1;
            min-width: 100px;
        }

        .remove-item {
            background: var(--accent-color);
            padding: 0.5rem 0.8rem;
            font-size: 0.85rem;
        }

        .remove-item:hover {
            background: #c0392b;
        }

        .add-item {
            background: var(--success-color);
            padding: 0.6rem 1.2rem;
        }

        .add-item:hover {
            background: #27ae60;
        }

        .sell-form {
            background: #f8f9fa;
            padding: 1.2rem;
            border-radius: var(--border-radius);
            margin: 1rem 0;
            box-shadow: var(--shadow);
        }

        .signature-section {
            margin-top: 1.5rem;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .signature-box {
            width: 45%;
        }

        .signature-box p {
            margin: 0.4rem 0;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .signature-line {
            border-top: 2px solid var(--text-color);
            margin-top: 2rem;
        }

        .delete-form, .view-form, .change-user-form {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            flex-wrap: wrap;
        }

        .delete-form input[type="password"], .change-user-form input[type="password"] {
            width: 100px;
            font-size: 0.85rem;
        }

        .search-form {
            margin-bottom: 1rem;
            display: flex;
            gap: 0.8rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-form input {
            width: 250px;
            max-width: 100%;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background: var(--card-bg);
            margin: 5% auto;
            padding: 1.2rem;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 700px;
            box-shadow: var(--shadow);
            animation: slideIn 0.3s ease;
        }

        .close {
            color: #7f8c8d;
            float: right;
            font-size: 1.2rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
        }

        .close:hover, .close:focus {
            color: var(--text-color);
        }

        .view-details {
            background: var(--info-color);
            padding: 0.6rem 1.2rem;
            font-size: 0.85rem;
        }

        .view-details:hover {
            background: #16a085;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @media print {
            body * { visibility: hidden; }
            #invoice, #invoice *, .modal-content, .modal-content * { visibility: visible; }
            #invoice, .modal-content { position: absolute; top: 0; left: 0; width: 100%; }
            #printBtn { display: none; }
            .close { display: none; }
        }

        /* Tablet (768px - 1024px) */
        @media (max-width: 1024px) {
            .container {
                max-width: 90%;
                padding: 1rem;
            }
            h2 {
                font-size: 1.4rem;
            }
            input, select, button {
                font-size: 0.9rem;
                padding: 0.5rem;
            }
            th, td {
                padding: 0.6rem;
                font-size: 0.85rem;
            }
            .item-row {
                gap: 0.6rem;
            }
            .modal-content {
                max-width: 600px;
                padding: 1rem;
            }
            .signature-section {
                flex-direction: column;
                gap: 0.8rem;
            }
            .signature-box {
                width: 100%;
            }
        }

        /* Mobile (below 768px) */
        @media (max-width: 768px) {
            body {
                padding: 0.5rem;
            }
            .container {
                margin: 0.5rem;
                padding: 0.8rem;
                width: 100%;
            }
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            h2 {
                font-size: 1.2rem;
                margin-bottom: 0.8rem;
            }
            input, select, button {
                font-size: 0.85rem;
                padding: 0.5rem;
                width: 100%;
            }
            button {
                padding: 0.5rem 1rem;
            }
            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            th, td {
                padding: 0.5rem;
                font-size: 0.8rem;
            }
            .item-row {
                flex-direction: column;
                gap: 0.5rem;
            }
            .item-row select, .item-row input {
                min-width: 100%;
            }
            .sell-form {
                padding: 0.8rem;
            }
            .search-form input {
                width: 100%;
            }
            .delete-form input[type="password"], .change-user-form input[type="password"] {
                width: 100%;
            }
            .modal-content {
                margin: 10% auto;
                padding: 0.8rem;
                max-width: 95%;
            }
            .close {
                font-size: 1rem;
            }
            .login-box {
                padding: 1rem;
                width: 95%;
            }
            .login-box h2 {
                font-size: 1.4rem;
            }
            .signature-section {
                flex-direction: column;
                gap: 0.5rem;
            }
            .signature-box {
                width: 100%;
            }
        }
    </style>
    <script>
        function printInvoice() {
            window.print();
        }

        function addItemRow() {
            const container = document.getElementById('items-container');
            if (!container) return;
            const index = container.children.length;
            const itemRow = document.createElement('div');
            itemRow.className = 'item-row';
            itemRow.innerHTML = `
                <select name="items[${index}][name]" required>
                    <option value="" disabled selected>Select Item</option>
                    <?php foreach ($inventory as $item): ?>
                        <option value="<?= htmlspecialchars($item['name']) ?>"><?= htmlspecialchars($item['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="items[${index}][quantity]" placeholder="Quantity" min="1" required>
                <input type="number" step="0.01" name="items[${index}][sell_price]" placeholder="Sell Price" min="0.01" required>
                <button type="button" class="remove-item" onclick="removeItemRow(this)">Remove</button>
            `;
            container.appendChild(itemRow);
        }

        function removeItemRow(button) {
            if (document.querySelectorAll('.item-row').length > 1) {
                button.parentElement.remove();
            } else {
                alert('At least one item is required.');
            }
        }

        function validateForm() {
            const itemRows = document.querySelectorAll('.item-row');
            let hasValidItem = false;

            for (let i = 0; i < itemRows.length; i++) {
                const row = itemRows[i];
                const name = row.querySelector('select').value;
                const quantity = parseInt(row.querySelector('input[type="number"][placeholder="Quantity"]').value);
                const sellPrice = parseFloat(row.querySelector('input[type="number"][placeholder="Sell Price"]').value);

                if (name && !isNaN(quantity) && !isNaN(sellPrice)) {
                    if (quantity <= 0 || sellPrice <= 0) {
                        alert(`Quantity and sell price for item "${name}" must be greater than 0.`);
                        return false;
                    }
                    hasValidItem = true;
                }
            }

            if (!hasValidItem) {
                alert('Please add at least one valid item to create an invoice.');
                return false;
            }

            return true;
        }

        function validateDeleteForm(form) {
            const password = form.querySelector('input[name="delete_password"]').value;
            if (!password) {
                alert('Please enter your password to delete the invoice.');
                return false;
            }
            return confirm('Are you sure you want to delete this invoice and all its items?');
        }

        function validateDeleteUserForm() {
            const username = document.querySelector('select[name="username_to_delete"]').value;
            if (!username) {
                alert('Please select a user to delete.');
                return false;
            }
            return confirm('Are you sure you want to delete the user "' + username + '"?');
        }

        function validateChangeUserForm() {
            const username = document.querySelector('select[name="username_to_change"]').value;
            const newUsername = document.querySelector('input[name="new_username"]').value;
            const newPassword = document.querySelector('input[name="new_password"]').value;
            const adminPassword = document.querySelector('input[name="admin_password"]').value;
            
            if (!username) {
                alert('Please select a user to change.');
                return false;
            }
            if (!newUsername || !newPassword || !adminPassword) {
                alert('All fields are required.');
                return false;
            }
            return confirm('Are you sure you want to change the details for user "' + username + '"?');
        }

        function showModal() {
            const modal = document.getElementById('invoiceModal');
            if (modal) {
                modal.style.display = 'block';
            }
        }

        function closeModal() {
            const modal = document.getElementById('invoiceModal');
            if (modal) {
                modal.style.display = 'none';
            }
        }

        window.onclick = function(event) {
            const modal = document.getElementById('invoiceModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }

        function adjustModalSize() {
            const modalContent = document.querySelector('.modal-content');
            if (modalContent) {
                if (window.innerWidth <= 768) {
                    modalContent.style.maxWidth = '95%';
                } else if (window.innerWidth <= 1024) {
                    modalContent.style.maxWidth = '600px';
                } else {
                    modalContent.style.maxWidth = '700px';
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('items-container')) {
                addItemRow();
            }
            <?php if ($invoice_details): ?>
                showModal();
            <?php endif; ?>
            adjustModalSize();
        });

        window.addEventListener('resize', adjustModalSize);
    </script>
</head>
<body>
<?php if (empty($_SESSION['logged_in'])): ?>
    <div class="login-box">
        <h2>Login to Shop System</h2>
        <?php if ($login_error): ?><p class="error"><?= htmlspecialchars($login_error) ?></p><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="login" value="1">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Log In</button>
        </form>
    </div>
<?php else: ?>
    <div class="container">
        <div class="header">
            <span>Welcome, <?= htmlspecialchars($_SESSION['username']) ?></span>
            <a href="?logout=1" class="logout">Logout</a>
        </div>

        <?php if (isset($error_message)): ?>
            <p class="error"><?= htmlspecialchars($error_message) ?></p>
        <?php endif; ?>

        <?php if (isset($success_message)): ?>
            <p class="success"><?= htmlspecialchars($success_message) ?></p>
        <?php endif; ?>

        <?php if (isset($delete_error)): ?>
            <p class="error"><?= htmlspecialchars($delete_error) ?></p>
        <?php endif; ?>

        <?php if (isset($search_error)): ?>
            <p class="error"><?= htmlspecialchars($search_error) ?></p>
        <?php endif; ?>

        <?php if (isset($change_user_error)): ?>
            <p class="error"><?= htmlspecialchars($change_user_error) ?></p>
        <?php endif; ?>

        <?php if (isset($change_user_success)): ?>
            <p class="success"><?= htmlspecialchars($change_user_success) ?></p>
        <?php endif; ?>

        <h2>Add Item to Inventory</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div style="display: flex; gap: 0.8rem; flex-wrap: wrap;">
                <input type="text" name="name" placeholder="Item Name" required>
                <input type="number" name="quantity" placeholder="Quantity" required>
                <input type="number" step="0.01" name="price" placeholder="Price" required>
                <button type="submit">Add to Inventory</button>
            </div>
        </form>

        <?php if ($_SESSION['username'] === 'Attiqullah'): ?>
        <h2>Add New User</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add_user">
            <div style="display: flex; gap: 0.8rem; flex-wrap: wrap;">
                <input type="text" name="username" placeholder="Username" required>
                <input type="password" name="password" placeholder="Password" required>
                <button type="submit">Add User</button>
            </div>
        </form>

        <h2>Change User Details</h2>
        <form method="POST" class="change-user-form" onsubmit="return validateChangeUserForm()">
            <input type="hidden" name="action" value="change_user">
            <div style="display: flex; gap: 0.8rem; flex-wrap: wrap;">
                <select name="username_to_change" required>
                    <option value="" disabled selected>Select User to Change</option>
                    <?php foreach ($users as $user): ?>
                        <?php if ($user['username'] !== 'Attiqullah'): ?>
                            <option value="<?= htmlspecialchars($user['username']) ?>"><?= htmlspecialchars($user['username']) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="new_username" placeholder="New Username" required>
                <input type="password" name="new_password" placeholder="New Password" required>
                <input type="password" name="admin_password" placeholder="Your Password" required>
                <button type="submit" style="background: var(--info-color);">Change User</button>
            </div>
        </form>

        <h2>Delete User</h2>
        <form method="POST" onsubmit="return validateDeleteUserForm()">
            <input type="hidden" name="action" value="delete_user">
            <div style="display: flex; gap: 0.8rem; flex-wrap: wrap;">
                <select name="username_to_delete" required>
                    <option value="" disabled selected>Select User to Delete</option>
                    <?php foreach ($users as $user): ?>
                        <?php if ($user['username'] !== 'Attiqullah'): ?>
                            <option value="<?= htmlspecialchars($user['username']) ?>"><?= htmlspecialchars($user['username']) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <button type="submit" style="background: var(--accent-color);">Delete User</button>
            </div>
        </form>
        <?php endif; ?>

        <div class="sell-form">
            <h2>Create Multi-Item Invoice</h2>
            <form method="POST" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="sell_multi">
                <div style="margin-bottom: 1rem;">
                    <input type="text" name="customer_name" placeholder="Customer Name" required style="width: 100%; max-width: 300px;">
                </div>
                <h3>Items to Sell:</h3>
                <div id="items-container"></div>
                <div style="margin: 1rem 0;">
                    <button type="button" class="add-item" onclick="addItemRow()">Add Another Item</button>
                </div>
                <button type="submit">Create Invoice</button>
            </form>
        </div>

        <h2>Inventory</h2>
        <table>
            <tr><th>Name</th><th>Qty</th><th>Price</th><th>Total Value</th></tr>
            <?php $inv_total = 0;
            foreach ($inventory as $it):
                $sub = $it['quantity'] * $it['price'];
                $inv_total += $sub;
            ?>
            <tr>
                <td><?= htmlspecialchars($it['name']) ?></td>
                <td><?= $it['quantity'] ?></td>
                <td>$<?= number_format($it['price'], 2) ?></td>
                <td>$<?= number_format($sub, 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="3"><strong>Inventory Total</strong></td>
                <td><strong>$<?= number_format($inv_total, 2) ?></strong></td>
            </tr>
        </table>

        <h2>Invoices</h2>
        <div class="search-form">
            <form method="POST">
                <input type="text" name="search_term" placeholder="Search by Customer Name or Invoice ID">
                <button type="submit" name="search">Search</button>
            </form>
        </div>
        <table>
            <tr><th>Invoice ID</th><th>Customer</th><th>Seller</th><th>Total Amount</th><th>Date</th><th>Action</th><th>Details</th></tr>
            <?php foreach ($invoices as $inv): ?>
            <tr>
                <td><?= $inv['invoice_id'] ?></td>
                <td><?= htmlspecialchars($inv['customer_name']) ?></td>
                <td><?= htmlspecialchars($inv['seller']) ?></td>
                <td>$<?= number_format($inv['total_amount'], 2) ?></td>
                <td><?= $inv['date'] ?></td>
                <td>
                    <form method="POST" class="delete-form" onsubmit="return validateDeleteForm(this)">
                        <input type="hidden" name="delete_invoice" value="<?= $inv['invoice_id'] ?>">
                        <input type="password" name="delete_password" placeholder="Enter Password" required>
                        <button type="submit" style="background: var(--accent-color);">Delete</button>
                    </form>
                </td>
                <td>
                    <form method="POST" class="view-form">
                        <input type="hidden" name="view_invoice" value="<?= $inv['invoice_id'] ?>">
                        <button type="submit" class="view-details">View Details</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="3"><strong>Total Sales</strong></td>
                <td><strong>$<?= number_format($total_sales, 2) ?></strong></td>
                <td colspan="3"></td>
            </tr>
        </table>

        <?php if ($last_invoice && !empty($last_invoice_items)): ?>
            <div id="invoice" style="margin-top: 1.5rem; background: var(--card-bg); padding: 1.2rem; border-radius: var(--border-radius); box-shadow: var(--shadow);">
                <h2>Last Invoice (#<?= $last_invoice['invoice_id'] ?>)</h2>
                <p><strong>Customer:</strong> <?= htmlspecialchars($last_invoice['customer_name']) ?></p>
                <p><strong>Seller:</strong> <?= htmlspecialchars($last_invoice['seller']) ?></p>
                <p><strong>Date:</strong> <?= $last_invoice['date'] ?></p>
                <table>
                    <tr><th>Item</th><th>Qty</th><th>Price</th><th>Subtotal</th><th>Total</th></tr>
                    <?php foreach ($last_invoice_items as $item):
                        $subtotal = $item['quantity'] * $item['sell_price'];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($item['item_name']) ?></td>
                        <td><?= $item['quantity'] ?></td>
                        <td>$<?= number_format($item['sell_price'], 2) ?></td>
                        <td>$<?= number_format($subtotal, 2) ?></td>
                        <td>$<?= number_format($item['total'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td colspan="4"><strong>Grand Total</strong></td>
                        <td><strong>$<?= number_format($last_invoice['total_amount'], 2) ?></strong></td>
                    </tr>
                </table>
                <div class="signature-section">
                    <div class="signature-box">
                        <p>Customer Signature:</p>
                        <div class="signature-line"></div>
                    </div>
                    <div class="signature-box">
                        <p>Seller Signature:</p>
                        <div class="signature-line"></div>
                    </div>
                </div>
                <button id="printBtn" onclick="printInvoice()">Print Invoice</button>
            </div>
        <?php endif; ?>

        <?php if ($invoice_details && !empty($invoice_details['items'])): ?>
            <div id="invoiceModal" class="modal">
                <div class="modal-content">
                    <span class="close" onclick="closeModal()">&times;</span>
                    <h2>Invoice Details (#<?= $invoice_details['invoice_id'] ?>)</h2>
                    <p><strong>Customer:</strong> <?= htmlspecialchars($invoice_details['customer_name']) ?></p>
                    <p><strong>Seller:</strong> <?= htmlspecialchars($invoice_details['seller']) ?></p>
                    <p><strong>Date:</strong> <?= $invoice_details['date'] ?></p>
                    <table>
                        <tr><th>Item</th><th>Qty</th><th>Price</th><th>Subtotal</th><th>Total</th></tr>
                        <?php foreach ($invoice_details['items'] as $item):
                            $subtotal = $item['quantity'] * $item['sell_price'];
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($item['item_name']) ?></td>
                            <td><?= $item['quantity'] ?></td>
                            <td>$<?= number_format($item['sell_price'], 2) ?></td>
                            <td>$<?= number_format($subtotal, 2) ?></td>
                            <td>$<?= number_format($item['total'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td colspan="4"><strong>Grand Total</strong></td>
                            <td><strong>$<?= number_format($invoice_details['total_amount'], 2) ?></strong></td>
                        </tr>
                    </table>
                    <div class="signature-section">
                        <div class="signature-box">
                            <p>Customer Signature:</p>
                            <div class="signature-line"></div>
                        </div>
                        <div class="signature-box">
                            <p>Seller Signature:</p>
                            <div class="signature-line"></div>
                        </div>
                    </div>
                    <button id="printBtn" onclick="printInvoice()">Print Invoice</button>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
</body>
</html>