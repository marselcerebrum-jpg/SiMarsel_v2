function readUser() {
    try {
        return JSON.parse(document.getElementById('session-user')?.textContent || 'null');
    } catch {
        return null;
    }
}

export default {
    user: readUser(),

    get isManager() {
        return this.user?.role?.code_role === 'MGR';
    },
};
