FROM postgres:16
WORKDIR /docker-entrypoint-initdb.d
ENV POSTGRES_DB flibusta

# Копируем схему БД
COPY init_db.sql /docker-entrypoint-initdb.d/

# Копируем пользовательскую конфигурацию PostgreSQL
COPY postgresql_custom.conf /etc/postgresql/

# Создаем конфиг с включением пользовательских настроек
RUN echo "include 'postgresql_custom.conf'" >> /etc/postgresql/postgresql.conf
