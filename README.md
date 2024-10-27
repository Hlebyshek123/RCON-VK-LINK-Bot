# 🖥 RCON-VK-LINK-Bot
ВКонтакте бот для привязки никнейма с сервера к профилю вк и управление им.

# 🚀 Установка
Все максимально подробно прописано в **readme.txt**

# 🥵 Запуск
Что бы запустить введите 
```
python3 glavniy.py
```

# **✨ Установка зависимостей**

*Вконтакте API*
```
pip3 install vk-api
```
*MCrcon*
```
pip3 install mcrcon
```
*PyYaml*
```
pip3 install pyyaml
```
*SQLite3*
```
sudo apt install sqlite3
```
*Requests*
```
pip3 install requests
```
*Python3.10*
```
ubuntu 22.04 уже имеет пайтон 3.10
```

# ⚙️ Плагиновые зависимости
Плагины:
HlebBans
vkplaytime
vkcode
vkProtection
vkmanager
2fa_vk
vkAuth
PurePerms

# 📝 Конфигурация

```yaml
# Файл конфигурации бота

#=====================================#
#            ВК НАСТРОЙКИ             #
#=====================================#

# ID сообщества VK (минус уже стоит внутри) 
group_id: 2218257  # Замените на ID вашего сообщества

# ID сообщества логов
logs_group_id: 2278604

# ID обсуждения в логах
logs_topic_id: 520684
from_group: 1 #если 0 отправка идет от админа если 1 то от сообщества)

# Токен доступа ВК для бота
access_token: "oQ"

# Токен доступа сообщества Логов (нужен токен пользователя админа)
logs_admin_token: "SGQLXHXpAPg1iniMLQ-DQ"

#=====================================#
#          RCON Настройки             #
#=====================================#

# Параметры ркон серверов
servers:
  grief:
    rcon_host: "149.1.69.76"
    rcon_port: 19133
    rcon_password: "DyZm88dWXu"
  linux:
    rcon_host: "149.1.69.76"
    rcon_port: 19100
    rcon_password: "w6QBtusWMX"
# список разрешенных серверов для рангов сервера
serv_perms:
  Helper:
    - "linux"
  Moderator:
    - "linux"
    - "grief"
  Support:
    - "linux"
    - "grief"
  SuperAdmin:
    - "grief"
    - "linux"
  Console:
    - "linux"
# Списки разрешённых команд для каждого ранга
permissions:
  Console:
    - "help"
  GlConsole:
    - "help"
    - "list"
  Developer:
    - "hban"
    - "hmute"
  Helper:
    - "msg"
    - "list"
    - "fpardon"
    - "time"
  Moderator:
    - "tp"
    - "givemoney"
    - "fpardon"
    - "time"
  Administrator:
    - "tp"
    - "givemoney"
    - "groups"
    - "fpardon"
    - "time"
  SeniorAdmin:
    - "fpardon"
    - "kill"
    - "time"
  Deputy:
    - "fpardon"
    - "gamemode"
    - "give"
    - "time"
  Support:
    - "groups"
    - "kill"
    - "fpardon"
    - "gamemode"
    - "give"
    - "time"
  Manager:
    - "groups"
    - "kill"
    - "fpardon"
    - "gamemode"
    - "give"
    - "time"
  Sponsor:
    - "*"
  SuperAdmin:
    - "*"  # "*" Всё Команды
#=====================================#
#            ДРУГОЕ                   #
#=====================================#
 # Админы вк (доступ к командам "помощь админ" и до 5акков на один вк айди)
admins:
  - 598807327  # VK ID первого админа
  - 741773416  # VK ID второго админа
  - 789886080  # VK ID третьего админа
  - 000000000  # и тд

#=====================================#
#             ЗАВИСИМОСТИ             #
#=====================================#

# HlebBans
# vkProtection
# vkplaytime
# vkAuth
# 2FA_VK
# VKManager
# VKCode
```

# 👑 Получение полных прав в боте
Чтоб стать супер админом в боте нужно получить ранг **SuperAdmin** и вставить свой ВК ID в массив admins в конфиге **config.yml** это гарантировано выдаст вам полный доступ в боте.

**Выдача рангов пользователю**
```
/gp [привязанный ник] [ранг]
```
**Выдача первого супер админа**
```
python3 adm_install.py
```

# ❤ Привязка к ВК
для того что бы связать **ВК ID** и **никнейм** пользователя нужно чтоб он отправил любое сообщение боту в лс потом зашел на сервер и прописал **/vkcode** после того как он получил свой вк код он заходит снова в бота и вводит команду
```
/привязать [никнейм в нижнем регистре] [вк код]
```
