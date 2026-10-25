-- Guys practice the ff. for the preparation of your Practical:
-- 1. How to open Xampp
    -- open xampp control panel -> start mysql module -> xampp shell -> `mysql -u root -p`

-- 2. Show Database
    SHOW DATABASES;
-- 3. Create Database
    CREATE DATABASE rad;
    USE rad;
-- 4. Create Table & fieldnames
    CREATE TABLE members(id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, name varchar(50) NOT NULL, age int(3) NOT NULL);

-- 5. Adding of records in the table
    INSERT INTO members(name, age) VALUES("Angel", 19),("Dan", 19),("Sanjoe", 18),("Nicole", 19),("Albie", 19),("Jerrian", 18);

-- 6. Inserting Fieldnames of the Table
    ALTER TABLE members ADD college varchar(50) NOT NULL AFTER age;

-- 7. Changing Fieldnames of the Table
    ALTER TABLE members CHANGE college uni varchar(50) NOT NULL;    

-- 8. Updating Records of the Table
    UPDATE members SET uni = "UCC"; 
    UPDATE members SET uni = "LCC" WHERE id=3;


/* 
heheheh
`ALTER TABLE <table_name> RENAME TO <new_table_name>;`
 */