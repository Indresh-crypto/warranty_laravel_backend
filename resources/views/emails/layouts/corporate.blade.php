<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
body {
    font-family: Arial, sans-serif;
    background: #f4f6f8;
    padding: 20px;
}
.container {
    max-width: 700px;
    background: #ffffff;
    margin: auto;
    border-radius: 6px;
    overflow: hidden;
}
.header {
    background: #0d47a1;
    color: #ffffff;
    padding: 20px;
    text-align: center;
}
.section {
    padding: 20px;
}
.table {
    width: 100%;
    border-collapse: collapse;
}
.table th, .table td {
    border: 1px solid #e0e0e0;
    padding: 10px;
}
.footer {
    background: #f1f1f1;
    padding: 15px;
    text-align: center;
    font-size: 12px;
    color: #555;
}
.badge {
    background: #2e7d32;
    color: #fff;
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 12px;
}
</style>
</head>
<body>
<div class="container">
    @yield('content')

    <div class="footer">
        © {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
    </div>
</div>
</body>
</html>