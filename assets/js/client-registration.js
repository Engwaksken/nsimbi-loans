document.addEventListener("DOMContentLoaded", function () {

    const steps = document.querySelectorAll(".form-step");
    const nextBtns = document.querySelectorAll(".next-btn");
    const prevBtns = document.querySelectorAll(".prev-btn");
    const stepIndicators = document.querySelectorAll(".step");
    const form = document.getElementById("multiStepForm");

    let currentStep = 0;

    const businessTypeSelect = document.getElementById("businessTypeSelect");
    const otherBusinessTypeField = document.getElementById("otherBusinessTypeField");
    const otherBusinessInput = document.getElementById("otherBusinessInput");
    const finalBusinessType = document.getElementById("finalBusinessType");

    let canvas = document.getElementById("signature-pad");
    let ctx = null;
    let isDrawing = false;
    let hasSignature = false;
    let lastX = 0;
    let lastY = 0;

    showStep(currentStep);
    initBusinessType();
    initSignaturePad();
    initPasswordToggle();

    function showStep(stepIndex) {
        steps.forEach(step => step.classList.remove("active"));

        if (steps[stepIndex]) {
            steps[stepIndex].classList.add("active");
        }

        // The signature step is hidden during initial page load. Size the
        // canvas only after the step is visible so it receives real dimensions.
        if (canvas && steps[stepIndex] && steps[stepIndex].contains(canvas)) {
            requestAnimationFrame(resizeCanvas);
        }

        stepIndicators.forEach((indicator, index) => {
            indicator.classList.remove("active", "completed");

            if (index === stepIndex) {
                indicator.classList.add("active");
            } else if (index < stepIndex) {
                indicator.classList.add("completed");
            }
        });

        window.scrollTo({ top: 0, behavior: "smooth" });
    }

    function initBusinessType() {
        if (!businessTypeSelect || !otherBusinessTypeField || !otherBusinessInput || !finalBusinessType) {
            return;
        }

        businessTypeSelect.removeAttribute("name");

        businessTypeSelect.addEventListener("change", updateBusinessType);
        otherBusinessInput.addEventListener("input", updateBusinessType);

        updateBusinessType();
    }

    function updateBusinessType() {
        const selectedValue = businessTypeSelect.value;

        businessTypeSelect.classList.remove("is-invalid");
        otherBusinessInput.classList.remove("is-invalid");
        finalBusinessType.classList.remove("is-invalid");

        if (selectedValue === "Other" || selectedValue === "other") {
            otherBusinessTypeField.style.display = "block";
            otherBusinessInput.required = true;

            finalBusinessType.value = otherBusinessInput.value.trim();
        } else {
            otherBusinessTypeField.style.display = "none";
            otherBusinessInput.required = false;
            otherBusinessInput.value = "";

            finalBusinessType.value = selectedValue;
        }
    }

    function validateStep(stepIndex) {
        updateBusinessType();
        saveSignature();

        const currentStepElement = steps[stepIndex];
        const inputs = currentStepElement.querySelectorAll("input[required], select[required], textarea[required]");

        let isValid = true;
        let firstInvalidInput = null;

        inputs.forEach(input => {
            input.classList.remove("is-invalid");

            if (input.offsetParent === null && input.type !== "hidden") {
                return;
            }

            if (input.type === "file") {
                if (!input.files || input.files.length === 0) {
                    isValid = false;
                    input.classList.add("is-invalid");
                    if (!firstInvalidInput) firstInvalidInput = input;
                }
            } else if (input.type === "email" && input.value.trim()) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

                if (!emailRegex.test(input.value.trim())) {
                    isValid = false;
                    input.classList.add("is-invalid");
                    if (!firstInvalidInput) firstInvalidInput = input;
                }
            } else if (!input.value.trim()) {
                isValid = false;
                input.classList.add("is-invalid");

                if (input.id === "finalBusinessType") {
                    if (businessTypeSelect.value === "Other" || businessTypeSelect.value === "other") {
                        otherBusinessInput.classList.add("is-invalid");
                        if (!firstInvalidInput) firstInvalidInput = otherBusinessInput;
                    } else {
                        businessTypeSelect.classList.add("is-invalid");
                        if (!firstInvalidInput) firstInvalidInput = businessTypeSelect;
                    }
                } else {
                    if (!firstInvalidInput) firstInvalidInput = input;
                }
            }
        });

        if (stepIndex === 2) {
            const password = currentStepElement.querySelector('input[name="password"]');
            const confirmPassword = currentStepElement.querySelector('input[name="con_password"]');

            if (password && confirmPassword && password.value !== confirmPassword.value) {
                isValid = false;
                confirmPassword.classList.add("is-invalid");
                if (!firstInvalidInput) firstInvalidInput = confirmPassword;
            }
        }

        if (!isValid && firstInvalidInput) {
            firstInvalidInput.focus();
            firstInvalidInput.scrollIntoView({ behavior: "smooth", block: "center" });
        }

        return isValid;
    }

    nextBtns.forEach(btn => {
        btn.addEventListener("click", function (e) {
            e.preventDefault();

            if (validateStep(currentStep)) {
                if (currentStep < steps.length - 1) {
                    currentStep++;
                    showStep(currentStep);
                }
            } else {
                alert("Please fill all required fields correctly before proceeding.");
            }
        });
    });

    prevBtns.forEach(btn => {
        btn.addEventListener("click", function (e) {
            e.preventDefault();

            if (currentStep > 0) {
                currentStep--;
                showStep(currentStep);
            }
        });
    });

    if (form) {
        form.addEventListener("submit", function (e) {
            e.preventDefault();

            updateBusinessType();
            saveSignature();

            if (!validateStep(currentStep)) {
                showMessage("Please fill all required fields correctly before submitting.", "error");
                return;
            }

            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;

            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Registering...';
            submitBtn.disabled = true;

            const formData = new FormData(form);

            fetch("/clients/added_client.php", {
                method: "POST",
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === "success") {
                    showMessage(data.message, "success");

                    if (data.redirect) {
                        setTimeout(() => {
                            window.location.href = data.redirect;
                        }, data.delay || 2000);
                    }
                } else {
                    showMessage(data.message || "Registration failed.", "error");
                }
            })
            .catch(error => {
                console.error("Error:", error);
                showMessage("An error occurred during registration. Please try again.", "error");
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
    }

    function showMessage(message, type) {
        const messageArea = document.getElementById("messageArea");
        const messageContent = document.getElementById("messageContent");

        if (!messageArea || !messageContent) {
            alert(message);
            return;
        }

        messageArea.className = `alert alert-${type === "success" ? "success" : "danger"}`;
        messageContent.innerHTML = `<i class="fas fa-${type === "success" ? "check-circle" : "exclamation-triangle"} me-2"></i>${message}`;
        messageArea.style.display = "block";

        messageArea.scrollIntoView({ behavior: "smooth", block: "center" });

        if (type === "success") {
            setTimeout(() => {
                messageArea.style.display = "none";
            }, 5000);
        }
    }

    const allInputs = document.querySelectorAll("input, select, textarea");

    allInputs.forEach(input => {
        input.addEventListener("blur", function () {
            updateBusinessType();

            this.classList.remove("is-invalid");

            if (this.hasAttribute("required") || (this.type === "email" && this.value.trim())) {
                if (this.type === "file") {
                    if (this.hasAttribute("required") && (!this.files || this.files.length === 0)) {
                        this.classList.add("is-invalid");
                    }
                } else if (this.type === "email" && this.value.trim()) {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

                    if (!emailRegex.test(this.value.trim())) {
                        this.classList.add("is-invalid");
                    }
                } else if (this.hasAttribute("required") && !this.value.trim()) {
                    this.classList.add("is-invalid");
                }
            }
        });
    });

    function initPasswordToggle() {
        document.querySelectorAll(".toggle-password").forEach(function (toggle) {
            toggle.addEventListener("click", function () {
                const input = document.querySelector(this.getAttribute("toggle"));

                if (!input) return;

                const type = input.getAttribute("type") === "password" ? "text" : "password";
                input.setAttribute("type", type);

                this.classList.toggle("fa-eye");
                this.classList.toggle("fa-eye-slash");
            });
        });
    }

    function initSignaturePad() {
        if (!canvas) return;

        ctx = canvas.getContext("2d");
        resizeCanvas();

        ctx.strokeStyle = "#000000";
        ctx.lineWidth = 2;
        ctx.lineCap = "round";
        ctx.lineJoin = "round";

        canvas.addEventListener("pointerdown", startPointerDrawing);
        canvas.addEventListener("pointermove", drawPointer);
        canvas.addEventListener("pointerup", stopPointerDrawing);
        canvas.addEventListener("pointercancel", stopPointerDrawing);
        canvas.addEventListener("pointerleave", stopPointerDrawing);

        const clearBtn = document.getElementById("clear-signature");

        if (clearBtn) {
            clearBtn.addEventListener("click", clearSignature);
        }

        window.addEventListener("resize", resizeCanvas);

        updateSignatureStatus();
    }

    function resizeCanvas() {
        if (!canvas || !ctx) return;

        const wrapper = canvas.parentElement;
        const rect = wrapper.getBoundingClientRect();

        if (rect.width <= 0 || rect.height <= 0) return;

        const oldSignature = hasSignature ? canvas.toDataURL("image/png") : null;
        const pixelRatio = window.devicePixelRatio || 1;

        canvas.width = Math.round(rect.width * pixelRatio);
        canvas.height = Math.round(rect.height * pixelRatio);
        canvas.style.width = rect.width + "px";
        canvas.style.height = rect.height + "px";
        ctx.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);

        ctx.strokeStyle = "#000000";
        ctx.lineWidth = 2;
        ctx.lineCap = "round";
        ctx.lineJoin = "round";

        if (oldSignature) {
            const img = new Image();
            img.onload = function () {
                 ctx.drawImage(img, 0, 0, rect.width, rect.height);
            };
            img.src = oldSignature;
        }
    }

    function getPointerPos(e) {
        const rect = canvas.getBoundingClientRect();

        return {
            x: e.clientX - rect.left,
            y: e.clientY - rect.top
        };
    }

    function startPointerDrawing(e) {
        if (!ctx) return;

        e.preventDefault();
        canvas.setPointerCapture(e.pointerId);
        isDrawing = true;
        const pos = getPointerPos(e);

        lastX = pos.x;
        lastY = pos.y;

        ctx.beginPath();
        ctx.moveTo(lastX, lastY);

        hasSignature = true;
        updateSignatureStatus();
    }

    function drawPointer(e) {
        if (!isDrawing || !ctx) return;

        e.preventDefault();
        const pos = getPointerPos(e);

        ctx.lineTo(pos.x, pos.y);
        ctx.stroke();

        lastX = pos.x;
        lastY = pos.y;

        saveSignature();
    }

    function stopPointerDrawing() {
        if (!isDrawing) return;

        isDrawing = false;
        saveSignature();

        if (ctx) {
            ctx.beginPath();
        }
    }

    function saveSignature() {
        const signatureData = document.getElementById("signature-data");

        if (!canvas || !signatureData || !hasSignature) return;

        try {
            signatureData.value = canvas.toDataURL("image/png");
        } catch (error) {
            console.error("Signature save error:", error);
        }
    }

    function clearSignature() {
        const signatureData = document.getElementById("signature-data");

        if (!ctx || !canvas) return;

        ctx.clearRect(0, 0, canvas.width, canvas.height);

        if (signatureData) {
            signatureData.value = "";
        }

        hasSignature = false;
        updateSignatureStatus();
    }

    function updateSignatureStatus() {
        const statusEl = document.getElementById("signature-status");

        if (!statusEl) return;

        if (hasSignature) {
            statusEl.textContent = "Signature captured";
            statusEl.className = "signature-status signed";
        } else {
            statusEl.textContent = "No signature drawn";
            statusEl.className = "signature-status empty";
        }
    }

});
