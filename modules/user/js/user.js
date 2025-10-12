function validateForm() {
    let name = document.forms["userForm"]["name"].value.trim();
    let email = document.forms["userForm"]["email"].value.trim();
    let password = document.forms["userForm"]["password"].value.trim();
    let valid = true;

    document.getElementById("nameError").innerText = "";
    document.getElementById("emailError").innerText = "";
    document.getElementById("passwordError").innerText = "";

    if (name.length < 2) {
        document.getElementById("nameError").innerText = "Name must be at least 2 characters!";
        valid = false;
    }

    let emailPattern = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
    if (!emailPattern.test(email)) {
        document.getElementById("emailError").innerText = "Enter a valid email!";
        valid = false;
    }

    let passwordPattern = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{6,}$/;
    if (!passwordPattern.test(password)) {
        document.getElementById("passwordError").innerText = "Password must be at least 6 characters with 1 uppercase, 1 lowercase, and 1 number!";
        valid = false;
    }

    if (valid) {
        document.querySelector('button').disabled = true;
        document.querySelector('button').innerText = 'Registering...';
    }

    return valid;
}