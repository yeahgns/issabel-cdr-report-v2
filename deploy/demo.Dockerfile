# Demo pública do relatório com dados fictícios (sem banco, sem Issabel).
# Build a partir da raiz do repositório:
#   docker build -f deploy/demo.Dockerfile -t relatorio-demo .
#   docker run -p 8080:80 relatorio-demo   ->  http://localhost:8080
FROM php:8.3-apache

ENV PORT=80
COPY . /var/www/html/

RUN cd /var/www/html \
 && rm -rf .git .gitignore deploy README.md config.php \
 && printf "<?php return array('demo' => true, 'company' => 'Empresa Demo');\n" > config.php \
 && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
 && sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
 && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf \
 && chown -R www-data:www-data /var/www/html
