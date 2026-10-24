In a separate Mac terminal, open the tunnel and leave it running:
ssh -N \
  -o ExitOnForwardFailure=yes \
  -o ServerAliveInterval=30 \
  -o ServerAliveCountMax=3 \
  -L 127.0.0.1:27718:127.0.0.1:27018 \
  root@YOUR_SERVER_IP