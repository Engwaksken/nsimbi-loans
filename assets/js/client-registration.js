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
    let strokes = [];      // [[{x, y}, ...], ...] as a share of the pad size
    let padWidth = 0;      // pad size in CSS pixels
    let padHeight = 0;

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

    /* ---------------------------------------------------------------
     * Signature pad: finger, stylus or mouse.
     *
     * Strokes are kept as points (in CSS pixels, as a share of the pad
     * width/height) so the signature can be redrawn sharply when the pad
     * changes size, e.g. when a phone is turned. Phones also fire "resize"
     * while scrolling (the address bar hides) and when the keyboard closes;
     * those only change the height of the window, so they are ignored and
     * never wipe the signature.
     * ------------------------------------------------------------- */
    function initSignaturePad() {
        if (!canvas) return;

        ctx = canvas.getContext("2d");
        resizeCanvas();

        if (window.PointerEvent) {
            canvas.addEventListener("pointerdown", function (e) {
                // Ignore a second finger and right/middle mouse buttons.
                if (!e.isPrimary || (e.pointerType === "mouse" && e.button !== 0)) return;
                e.preventDefault();
                try { canvas.setPointerCapture(e.pointerId); } catch (err) { /* older browsers */ }
                startStroke(e.clientX, e.clientY);
            });
            canvas.addEventListener("pointermove", function (e) {
                if (!isDrawing || !e.isPrimary) return;
                e.preventDefault();
                // Use every point the screen recorded, for smooth fast strokes.
                const events = typeof e.getCoalescedEvents === "function" ? e.getCoalescedEvents() : [];
                (events.length ? events : [e]).forEach(function (p) { extendStroke(p.clientX, p.clientY); });
            });
            canvas.addEventListener("pointerup", endStroke);
            canvas.addEventListener("pointercancel", endStroke);
        } else {
            // Older phone browsers without pointer events.
            canvas.addEventListener("touchstart", function (e) {
                if (e.touches.length !== 1) return;
                e.preventDefault();
                startStroke(e.touches[0].clientX, e.touches[0].clientY);
            }, { passive: false });
            canvas.addEventListener("touchmove", function (e) {
                if (!isDrawing) return;
                e.preventDefault();
                extendStroke(e.touches[0].clientX, e.touches[0].clientY);
            }, { passive: false });
            canvas.addEventListener("touchend", endStroke);
            canvas.addEventListener("touchcancel", endStroke);
            canvas.addEventListener("mousedown", function (e) {
                if (e.button !== 0) return;
                startStroke(e.clientX, e.clientY);
            });
            window.addEventListener("mousemove", function (e) {
                if (isDrawing) extendStroke(e.clientX, e.clientY);
            });
            window.addEventListener("mouseup", endStroke);
        }

        const clearBtn = document.getElementById("clear-signature");

        if (clearBtn) {
            clearBtn.addEventListener("click", clearSignature);
        }

        window.addEventListener("resize", resizeCanvas);
        window.addEventListener("orientationchange", function () {
            setTimeout(resizeCanvas, 250);
        });

        updateSignatureStatus();
    }

    function resizeCanvas() {
        if (!canvas || !ctx) return;

        const rect = canvas.parentElement.getBoundingClientRect();

        if (rect.width <= 0 || rect.height <= 0) return;
        // Same size (address bar or keyboard moved): nothing to do. Never
        // resize in the middle of a stroke.
        if (isDrawing || (Math.round(rect.width) === padWidth && Math.round(rect.height) === padHeight)) return;

        padWidth = Math.round(rect.width);
        padHeight = Math.round(rect.height);
        const pixelRatio = window.devicePixelRatio || 1;

        canvas.width = Math.round(padWidth * pixelRatio);
        canvas.height = Math.round(padHeight * pixelRatio);
        canvas.style.width = padWidth + "px";
        canvas.style.height = padHeight + "px";
        ctx.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);

        redrawSignature();
    }

    function penStyle() {
        ctx.strokeStyle = "#0f172a";
        ctx.fillStyle = "#0f172a";
        // A little thicker on small screens, where fingers are used.
        ctx.lineWidth = padWidth < 500 ? 2.6 : 2.2;
        ctx.lineCap = "round";
        ctx.lineJoin = "round";
    }

    function pointFromClient(clientX, clientY) {
        const rect = canvas.getBoundingClientRect();

        // Stored as a share of the pad so a resize keeps the shape.
        return {
            x: (clientX - rect.left) / rect.width,
            y: (clientY - rect.top) / rect.height
        };
    }

    function toPad(p) {
        return { x: p.x * padWidth, y: p.y * padHeight };
    }

    function startStroke(clientX, clientY) {
        if (!ctx) return;

        isDrawing = true;
        strokes.push([pointFromClient(clientX, clientY)]);
        // A tap leaves a dot until the finger moves.
        drawDot(strokes[strokes.length - 1][0]);

        hasSignature = true;
        updateSignatureStatus();
    }

    function extendStroke(clientX, clientY) {
        if (!isDrawing || !ctx) return;

        const stroke = strokes[strokes.length - 1];
        const p = pointFromClient(clientX, clientY);
        const prev = stroke[stroke.length - 1];

        // Skip jitter smaller than about half a pixel.
        if (Math.abs((p.x - prev.x) * padWidth) < 0.5 && Math.abs((p.y - prev.y) * padHeight) < 0.5) return;

        stroke.push(p);
        drawSegment(stroke, stroke.length - 1);
    }

    function endStroke() {
        if (!isDrawing) return;

        isDrawing = false;
        // Save once per stroke; saving on every move makes phones lag.
        saveSignature();
    }

    function drawDot(p) {
        const q = toPad(p);

        penStyle();
        ctx.beginPath();
        ctx.arc(q.x, q.y, ctx.lineWidth / 2, 0, Math.PI * 2);
        ctx.fill();
    }

    // Smooth curve through the midpoints of the last three points.
    function drawSegment(stroke, i) {
        const a = toPad(stroke[Math.max(0, i - 2)]);
        const b = toPad(stroke[i - 1]);
        const c = toPad(stroke[i]);
        const start = i < 2 ? a : { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
        const end = { x: (b.x + c.x) / 2, y: (b.y + c.y) / 2 };

        penStyle();
        ctx.beginPath();
        ctx.moveTo(start.x, start.y);
        ctx.quadraticCurveTo(b.x, b.y, end.x, end.y);
        ctx.stroke();
    }

    function redrawSignature() {
        ctx.clearRect(0, 0, padWidth, padHeight);

        strokes.forEach(function (stroke) {
            drawDot(stroke[0]);
            for (let i = 1; i < stroke.length; i++) {
                drawSegment(stroke, i);
            }
        });
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

        strokes = [];
        isDrawing = false;
        ctx.clearRect(0, 0, padWidth, padHeight);

        if (signatureData) {
            signatureData.value = "";
        }

        hasSignature = false;
        updateSignatureStatus();
    }

    function updateSignatureStatus() {
        const statusEl = document.getElementById("signature-status");
        const wrapper = canvas ? canvas.parentElement : null;

        if (wrapper) {
            wrapper.classList.toggle("has-signature", hasSignature);
        }

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
