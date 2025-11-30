FROM mariadb:11.8

# Copy custom configuration into the image so we don't mount a host file
COPY my.cnf /etc/mysql/conf.d/my.cnf

# Ensure correct permissions
RUN chmod 644 /etc/mysql/conf.d/my.cnf
