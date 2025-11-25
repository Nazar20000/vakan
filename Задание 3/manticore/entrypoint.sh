set -e

echo "Waiting for MySQL to be fully ready..."
sleep 10

echo "MySQL should be ready now!"

if [ ! -f /var/lib/manticore/vacancy.spd ]; then
    echo "Creating vacancy index..."
    indexer --config /etc/manticoresearch/manticore.conf --all
    echo "Index created successfully!"
else
    echo "Index already exists, skipping creation."
fi

echo "Starting Manticoresearch daemon..."
exec searchd --config /etc/manticoresearch/manticore.conf --nodetach

