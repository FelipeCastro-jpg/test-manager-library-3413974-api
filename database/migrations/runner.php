<?php

use App\Core\Database;

function run_migrations(): void {
    $db = Database::getConnection();

    echo "Running migrations for schema 'library'...\n";

    // 1. Esquema
    $db->exec("CREATE SCHEMA IF NOT EXISTS library;");
    $db->exec("SET search_path TO library, public;");

    // 2. Extensión uuid-ossp si no existe
    $db->exec("CREATE EXTENSION IF NOT EXISTS \"uuid-ossp\";");

    // 3. Tabla category
    $db->exec("
        CREATE TABLE IF NOT EXISTS library.category (
            id UUID PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE
        );
    ");

    // Sembrar las 5 categorías fijas (§9)
    $categories = [
        ['11111111-1111-4111-8111-111111111111', 'Novela'],
        ['22222222-2222-4222-8222-222222222222', 'Ciencia'],
        ['33333333-3333-4333-8333-333333333333', 'Tecnología'],
        ['44444444-4444-4444-8444-444444444444', 'Historia'],
        ['55555555-5555-4555-8555-555555555555', 'Infantil'],
    ];
    $stmtCat = $db->prepare("
        INSERT INTO library.category (id, name)
        VALUES (:id, :name)
        ON CONFLICT (id) DO NOTHING;
    ");
    foreach ($categories as $cat) {
        $stmtCat->execute(['id' => $cat[0], 'name' => $cat[1]]);
    }
    echo " [✓] Table 'library.category' created and 5 categories seeded.\n";

    // 4. Tabla user
    $db->exec("
        CREATE TABLE IF NOT EXISTS library.\"user\" (
            id UUID PRIMARY KEY,
            username VARCHAR(120) NOT NULL UNIQUE,
            password_hash VARCHAR(512) NOT NULL,
            role VARCHAR(40) NOT NULL,
            CONSTRAINT ck_user_role CHECK (role IN ('admin', 'librarian'))
        );
    ");
    echo " [✓] Table 'library.user' created.\n";

    // 5. Tabla book
    $db->exec("
        CREATE TABLE IF NOT EXISTS library.book (
            id UUID PRIMARY KEY,
            title VARCHAR(250) NOT NULL,
            author VARCHAR(200) NOT NULL,
            isbn CHAR(13) NOT NULL,
            category_id UUID NOT NULL REFERENCES library.category(id) ON DELETE RESTRICT,
            total_copies INTEGER NOT NULL,
            available_copies INTEGER NOT NULL,
            cover_key VARCHAR(512) NULL,
            deleted_at TIMESTAMPTZ NULL,
            CONSTRAINT ck_book_total_positive CHECK (total_copies >= 1),
            CONSTRAINT ck_book_available_range CHECK (available_copies >= 0 AND available_copies <= total_copies)
        );
    ");
    // Índice parcial sobre libros activos
    $db->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS uq_book_isbn_active
        ON library.book (isbn)
        WHERE deleted_at IS NULL;
    ");
    echo " [✓] Table 'library.book' created with check constraints and partial index.\n";

    // 6. Tabla member
    $db->exec("
        CREATE TABLE IF NOT EXISTS library.member (
            id UUID PRIMARY KEY,
            full_name VARCHAR(200) NOT NULL,
            document_number VARCHAR(30) NOT NULL UNIQUE
        );
    ");
    echo " [✓] Table 'library.member' created.\n";

    // 7. Tabla loan
    $db->exec("
        CREATE TABLE IF NOT EXISTS library.loan (
            id UUID PRIMARY KEY,
            member_id UUID NOT NULL REFERENCES library.member(id) ON DELETE RESTRICT,
            loaned_by_user_id UUID NOT NULL REFERENCES library.\"user\"(id) ON DELETE RESTRICT,
            loaned_at TIMESTAMPTZ NOT NULL
        );
    ");
    echo " [✓] Table 'library.loan' created.\n";

    // 8. Tabla loan_item
    $db->exec("
        CREATE TABLE IF NOT EXISTS library.loan_item (
            id UUID PRIMARY KEY,
            loan_id UUID NOT NULL REFERENCES library.loan(id) ON DELETE CASCADE,
            book_id UUID NOT NULL REFERENCES library.book(id) ON DELETE RESTRICT,
            book_title VARCHAR(250) NOT NULL,
            isbn CHAR(13) NOT NULL,
            category_name VARCHAR(120) NOT NULL,
            due_at TIMESTAMPTZ NOT NULL,
            returned_at TIMESTAMPTZ NULL,
            CONSTRAINT uq_loan_book UNIQUE (loan_id, book_id)
        );
    ");
    echo " [✓] Table 'library.loan_item' created.\n";

    // Sembrar Admin inicial (§9 y T-17)
    require_once __DIR__ . '/../seeders/InitialAdminSeeder.php';
    InitialAdminSeeder::run();

    echo "All migrations applied successfully!\n";
}

