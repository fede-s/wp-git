# GitHub Git Pull — WordPress Plugin

Pull from a GitHub repository directly from the WordPress admin dashboard.

---

## Installation

1. Upload the `github-git-pull` folder to `/wp-content/plugins/`
2. Activate the plugin via **Plugins → Installed Plugins**
3. Go to **Git Pull → Settings** in the admin sidebar

---

## Configuration

| Setting | Description |
|---|---|
| **Repository Path** | Absolute path on the server to the directory containing `.git` (e.g. `/var/www/html`) |
| **Branch** | Branch to pull from (default: `main`) |
| **Remote** | Git remote name (default: `origin`) |
| **Git Binary Path** | Full path to git — run `which git` on your server |
| **SSH Key Path** | Optional — path to SSH private key if using SSH remotes |

---

## Lightsail Setup (SSH Authentication — Recommended)

```bash
# 1. Generate a deploy key for the www-data user
sudo -u www-data ssh-keygen -t ed25519 -C "lightsail-deploy" -f /var/www/.ssh/id_ed25519 -N ""

# 2. Print the public key — add this to GitHub repo → Settings → Deploy keys
sudo cat /var/www/.ssh/id_ed25519.pub

# 3. Fix permissions
sudo chown -R www-data:www-data /var/www/.ssh
sudo chmod 700 /var/www/.ssh
sudo chmod 600 /var/www/.ssh/id_ed25519

# 4. Test the connection as www-data
sudo -u www-data git -C /var/www/html pull origin main
```

Set **SSH Key Path** in the plugin settings to `/var/www/.ssh/id_ed25519`.

---

## HTTPS Authentication (Personal Access Token)

Update your git remote to embed the token:

```bash
cd /var/www/html
git remote set-url origin https://<YOUR_TOKEN>@github.com/<user>/<repo>.git
```

Leave **SSH Key Path** blank in settings.

---

## Server Requirements

- PHP `exec()` must be enabled (not disabled in `php.ini`)
- The web server user (`www-data` / `apache`) must have write access to the repo directory
- `git` must be installed on the server (`sudo apt install git`)

---

## Troubleshooting

| Problem | Fix |
|---|---|
| `git binary not found` | Run `which git` and update the Git Binary Path setting |
| `Permission denied` | Check that `www-data` owns the `.git` directory |
| `Host key verification failed` | The SSH key path is set and the key is added to GitHub |
| `exec()` disabled | Contact host or check `php.ini` for `disable_functions` |
