@props(['title'])

<!DOCTYPE html>
<html lang="en">
    <head>
        <title>{{ $title }}</title>
    </head>
    <body>
        <main>{{ $slot }}</main>
    </body>
</html>
