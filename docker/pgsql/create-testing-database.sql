SELECT 'CREATE DATABASE promptedit_test'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'promptedit_test')\gexec
