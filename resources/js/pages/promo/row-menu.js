/**
 * "More actions" menu of a table row. The menu is fixed-positioned under its button, so the
 * scrolling table does not clip it. Escape and Tab close it; Escape returns focus to the button.
 */
export default function promoRowMenu() {
    return {
        open: false,
        position: '',

        toggle() {
            this.open ? this.close() : this.show();
        },

        show() {
            const rect = this.$refs.trigger.getBoundingClientRect();
            this.position = `top: ${rect.bottom + 4}px; right: ${window.innerWidth - rect.right}px;`;
            this.open = true;
            this.$nextTick(() => this.$refs.menu.querySelector('[role="menuitem"]')?.focus());
        },

        close(returnFocus = false) {
            if (!this.open) return;

            this.open = false;
            if (returnFocus) this.$refs.trigger.focus();
        },
    };
}
