const express = require('express');

const app = express();

const server = app.listen(3001, function() {
    console.log('server running on port 3001');
});

const io = require('socket.io')(server);

io.on('connection', function(socket) {
    var total = io.engine.clientsCount;
    io.sockets.emit('CONNECTIONS', total);

    socket.on("disconnect", () => {
      io.sockets.emit('CONNECTIONS', total);
    });

    socket.on('SEND_MESSAGE', function(data) {
        io.emit('MESSAGE', data)
    });
});