<?php
// db_schema.php
// Este arquivo contém a estrutura das tabelas e a semente inicial para o superadmin

function get_db_schema() {
    return [
        "config_smtp" => "
            CREATE TABLE IF NOT EXISTS `config_smtp` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `host` VARCHAR(255) NOT NULL,
                `port` INT NOT NULL DEFAULT 587,
                `username` VARCHAR(255) NULL,
                `password` VARCHAR(255) NULL,
                `encryption` VARCHAR(50) NOT NULL DEFAULT 'tls',
                `from_email` VARCHAR(255) NOT NULL,
                `from_name` VARCHAR(255) NOT NULL,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        
        "choirs" => "
            CREATE TABLE IF NOT EXISTS `choirs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NOT NULL,
                `description` TEXT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        
        "users" => "
            CREATE TABLE IF NOT EXISTS `users` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `choir_id` INT NULL,
                `name` VARCHAR(255) NOT NULL,
                `email` VARCHAR(255) NOT NULL UNIQUE,
                `phone` VARCHAR(100) NULL,
                `username` VARCHAR(255) NOT NULL UNIQUE,
                `password` VARCHAR(255) NOT NULL,
                `role` ENUM('superadmin', 'administrador', 'financeiro', 'colaborador', 'membro') NOT NULL,
                `voice_type` VARCHAR(100) NULL,
                `balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `status` VARCHAR(50) NOT NULL DEFAULT 'active',
                `reset_token` VARCHAR(255) NULL,
                `reset_expires` DATETIME NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT `fk_users_choir` FOREIGN KEY (`choir_id`) REFERENCES `choirs` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        
        "billing_items" => "
            CREATE TABLE IF NOT EXISTS `billing_items` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `choir_id` INT NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `description` TEXT NULL,
                `amount` DECIMAL(10,2) NOT NULL,
                `type` VARCHAR(50) NOT NULL DEFAULT 'eventual', -- 'eventual' ou 'recurring'
                `due_date` DATE NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT `fk_billing_choir` FOREIGN KEY (`choir_id`) REFERENCES `choirs` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        
        "member_billing" => "
            CREATE TABLE IF NOT EXISTS `member_billing` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `member_id` INT NOT NULL,
                `billing_item_id` INT NOT NULL,
                `status` ENUM('open', 'paid', 'pending_approval') NOT NULL DEFAULT 'open',
                `paid_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `due_date` DATE NOT NULL,
                `paid_at` DATETIME NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT `fk_mb_member` FOREIGN KEY (`member_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_mb_item` FOREIGN KEY (`billing_item_id`) REFERENCES `billing_items` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        
        "receipts" => "
            CREATE TABLE IF NOT EXISTS `receipts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `member_id` INT NOT NULL,
                `amount` DECIMAL(10,2) NOT NULL,
                `filename` VARCHAR(255) NOT NULL,
                `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
                `description` TEXT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `approved_at` DATETIME NULL,
                `approved_by` INT NULL,
                CONSTRAINT `fk_receipts_member` FOREIGN KEY (`member_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_receipts_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        
        "receipt_billing_items" => "
            CREATE TABLE IF NOT EXISTS `receipt_billing_items` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `receipt_id` INT NOT NULL,
                `member_billing_id` INT NOT NULL,
                CONSTRAINT `fk_rbi_receipt` FOREIGN KEY (`receipt_id`) REFERENCES `receipts` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_rbi_mb` FOREIGN KEY (`member_billing_id`) REFERENCES `member_billing` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        "
    ];
}

function sync_database(PDO $pdo) {
    $results = [];
    $schema = get_db_schema();
    
    // Desabilitar chaves estrangeiras temporariamente para sincronização limpa se necessário
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    
    foreach ($schema as $tableName => $sql) {
        try {
            $pdo->exec($sql);
            $results[$tableName] = ['success' => true, 'message' => "Tabela '$tableName' sincronizada com sucesso."];
        } catch (PDOException $e) {
            $results[$tableName] = ['success' => false, 'message' => "Erro ao sincronizar '$tableName': " . $e->getMessage()];
        }
    }
    
    // Inserir SMTP padrão se não houver registros
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM config_smtp");
        if ($stmt->fetchColumn() == 0) {
            $pdo->exec("INSERT INTO config_smtp (host, port, username, password, encryption, from_email, from_name) 
                       VALUES ('smtp.example.com', 587, 'user@example.com', 'pass', 'tls', 'no-reply@ecoral.com.br', 'eCoral SaaS')");
            $results['seed_smtp'] = ['success' => true, 'message' => "Configuração padrão de SMTP criada."];
        }
    } catch (PDOException $e) {
        $results['seed_smtp'] = ['success' => false, 'message' => "Erro ao inserir SMTP padrão: " . $e->getMessage()];
    }

    // Inserir superadmin padrão
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'superadmin'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $hashedPassword = password_hash('coral123', PASSWORD_BCRYPT);
            $stmtInsert = $pdo->prepare("INSERT INTO users (name, email, username, password, role, status) 
                                        VALUES ('Super Admin', 'sadmin@ecoral.com', 'sadmin', ?, 'superadmin', 'active')");
            $stmtInsert->execute([$hashedPassword]);
            $results['seed_sadmin'] = ['success' => true, 'message' => "Superadmin inicial criado (usuário: sadmin, senha: coral123)."];
        }
    } catch (PDOException $e) {
        $results['seed_sadmin'] = ['success' => false, 'message' => "Erro ao criar superadmin: " . $e->getMessage()];
    }
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    return $results;
}
