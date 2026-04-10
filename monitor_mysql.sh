#!/bin/bash
# 监控 MySQL 连接数

echo "Monitoring MySQL connections..."
for i in {1..20}; do
    php -r "
require '/Users/jh/Data/Code/Project/telegram-bots/vendor/autoload.php';
\$config = require '/Users/jh/Data/Code/Project/telegram-bots/config/database.php';
\$pdo = new PDO('mysql:host=' . \$config['mysql']['host'], \$config['mysql']['user'], \$config['mysql']['password']);
\$stmt = \$pdo->query('SHOW PROCESSLIST');
\$count = count(\$stmt->fetchAll());
echo date('H:i:s') . ' - Connections: ' . \$count . PHP_EOL;
"
    sleep 10
done
