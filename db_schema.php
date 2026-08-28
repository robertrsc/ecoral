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
                `code` VARCHAR(50) UNIQUE NULL,
                `description` TEXT NULL,
                `logo` VARCHAR(255) NULL,
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
                `member_code` VARCHAR(100) UNIQUE NULL,
                `balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `status` VARCHAR(50) NOT NULL DEFAULT 'active',
                `cpf` VARCHAR(20) NULL,
                `rg` VARCHAR(20) NULL,
                `address` VARCHAR(255) NULL,
                `address_number` VARCHAR(50) NULL,
                `address_neighborhood` VARCHAR(100) NULL,
                `address_zip_code` VARCHAR(20) NULL,
                `address_city` VARCHAR(100) NULL,
                `address_state` VARCHAR(2) NULL,
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
                `start_date` DATE NULL,
                `end_date` DATE NULL,
                `target_type` VARCHAR(50) NOT NULL DEFAULT 'all',
                `target_members` TEXT NULL,
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
        ",
        
        "events" => "
            CREATE TABLE IF NOT EXISTS `events` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `choir_id` INT NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `start_time` DATETIME NOT NULL,
                `end_time` DATETIME NOT NULL,
                `location` VARCHAR(255) NULL,
                `notes` TEXT NULL,
                `target_type` VARCHAR(50) NOT NULL DEFAULT 'all',
                `target_voice_type` VARCHAR(100) NULL,
                `target_member_id` INT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT `fk_events_choir` FOREIGN KEY (`choir_id`) REFERENCES `choirs` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_events_member` FOREIGN KEY (`target_member_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        
        "event_responses" => "
            CREATE TABLE IF NOT EXISTS `event_responses` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `event_id` INT NOT NULL,
                `member_id` INT NOT NULL,
                `response` ENUM('going', 'not_going') NOT NULL,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_er_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_er_member` FOREIGN KEY (`member_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                UNIQUE KEY `uk_event_member` (`event_id`, `member_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        
        "shared_files" => "
            CREATE TABLE IF NOT EXISTS `shared_files` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `choir_id` INT NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `filename` VARCHAR(255) NOT NULL,
                `original_name` VARCHAR(255) NOT NULL,
                `file_type` VARCHAR(100) NOT NULL,
                `file_size` INT NOT NULL,
                `target_type` VARCHAR(50) NOT NULL DEFAULT 'all',
                `target_voice_type` VARCHAR(100) NULL,
                `target_member_id` INT NULL,
                `uploaded_by` INT NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT `fk_sf_choir` FOREIGN KEY (`choir_id`) REFERENCES `choirs` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_sf_member` FOREIGN KEY (`target_member_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_sf_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
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
    
    // Sincronizar coluna member_code na tabela users
    try {
        $stmtCheck = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'member_code'");
        if ($stmtCheck->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `member_code` VARCHAR(100) UNIQUE NULL;");
            $results['users_alter_member_code'] = ['success' => true, 'message' => "Coluna 'member_code' adicionada à tabela 'users'."];
        }
    } catch (PDOException $e) {
        $results['users_alter_member_code'] = ['success' => false, 'message' => "Erro ao adicionar 'member_code': " . $e->getMessage()];
    }

    // Sincronizar novas colunas de perfil na tabela users
    try {
        $profileCols = [
            'cpf' => "VARCHAR(20) NULL",
            'rg' => "VARCHAR(20) NULL",
            'address' => "VARCHAR(255) NULL",
            'address_number' => "VARCHAR(50) NULL",
            'address_neighborhood' => "VARCHAR(100) NULL",
            'address_zip_code' => "VARCHAR(20) NULL",
            'address_city' => "VARCHAR(100) NULL",
            'address_state' => "VARCHAR(2) NULL"
        ];
        foreach ($profileCols as $colName => $colDef) {
            $stmtCheck = $pdo->query("SHOW COLUMNS FROM `users` LIKE '$colName'");
            if ($stmtCheck->rowCount() === 0) {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `$colName` $colDef;");
                $results['users_alter_' . $colName] = ['success' => true, 'message' => "Coluna '$colName' adicionada à tabela 'users'."];
            }
        }
    } catch (PDOException $e) {
        $results['users_alter_profile_columns'] = ['success' => false, 'message' => "Erro ao adicionar colunas de perfil em users: " . $e->getMessage()];
    }

    // Sincronizar colunas de branding na tabela choirs (code e logo)
    try {
        $choirCols = [
            'code' => "VARCHAR(50) UNIQUE NULL",
            'logo' => "VARCHAR(255) NULL"
        ];
        foreach ($choirCols as $colName => $colDef) {
            $stmtCheck = $pdo->query("SHOW COLUMNS FROM `choirs` LIKE '$colName'");
            if ($stmtCheck->rowCount() === 0) {
                // Se for UNIQUE, não usamos ADD COLUMN UNIQUE diretamente para evitar colisões com nulos se houver registros antigos, 
                // mas no MySQL VARCHAR(50) UNIQUE NULL funciona perfeitamente com múltiplos nulos.
                $pdo->exec("ALTER TABLE `choirs` ADD COLUMN `$colName` $colDef;");
                $results['choirs_alter_' . $colName] = ['success' => true, 'message' => "Coluna '$colName' adicionada à tabela 'choirs'."];
            }
        }
    } catch (PDOException $e) {
        $results['choirs_alter_columns'] = ['success' => false, 'message' => "Erro ao adicionar colunas de branding em choirs: " . $e->getMessage()];
    }

    // Sincronizar colunas de recorrencia na tabela billing_items
    try {
        $cols = [
            'start_date' => "DATE NULL",
            'end_date' => "DATE NULL",
            'target_type' => "VARCHAR(50) NOT NULL DEFAULT 'all'",
            'target_members' => "TEXT NULL"
        ];
        foreach ($cols as $colName => $colDef) {
            $stmtCheck = $pdo->query("SHOW COLUMNS FROM `billing_items` LIKE '$colName'");
            if ($stmtCheck->rowCount() === 0) {
                $pdo->exec("ALTER TABLE `billing_items` ADD COLUMN `$colName` $colDef;");
                $results['billing_items_alter_' . $colName] = ['success' => true, 'message' => "Coluna '$colName' adicionada à tabela 'billing_items'."];
            }
        }
    } catch (PDOException $e) {
        $results['billing_items_alter_recorrencia'] = ['success' => false, 'message' => "Erro ao adicionar colunas de recorrência: " . $e->getMessage()];
    }

    // Preencher códigos para membros existentes que não tenham um
    try {
        $stmtNull = $pdo->query("SELECT id, voice_type, choir_id FROM users WHERE role = 'membro' AND member_code IS NULL");
        $membersToUpdate = $stmtNull->fetchAll();
        if (!empty($membersToUpdate)) {
            $stmtUpdateCode = $pdo->prepare("UPDATE users SET member_code = ? WHERE id = ?");
            foreach ($membersToUpdate as $m) {
                // Generate code using global helper
                $code = get_or_generate_member_code($pdo, $m['voice_type'], $m['choir_id'], $m['id']);
                $stmtUpdateCode->execute([$code, $m['id']]);
            }
            $results['users_fill_codes'] = ['success' => true, 'message' => count($membersToUpdate) . " membros existentes atualizados com um código único."];
        }
    } catch (PDOException $e) {
        $results['users_fill_codes'] = ['success' => false, 'message' => "Erro ao preencher códigos de membros: " . $e->getMessage()];
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    return $results;
}
