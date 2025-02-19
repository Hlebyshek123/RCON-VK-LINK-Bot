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
*MySQL*
```
sudo apt install mysql-connector-python
```
*SQLite3*
```
sudo pip3 install sqlite3
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
(LiteCore 1.1.x)
Плагины:
HlebBans
playtime
Protection
vkmanager
Auth
PurePerms

# 📝 Конфигурация

```yaml
# Файл конфигурации бота

#=====================================#
#            ВК НАСТРОЙКИ             #
#=====================================#

# ID сообщества VK (минус уже стоит внутри) 
group_id: 21946796 # Замените на ID вашего сообщества

# ID сообщества логов
logs_group_id: 22923390

# ID обсуждения в логах
logs_topic_id: 53828441
from_group: 1 #если 0 отправка идет от админа если 1 то от сообщества)

# Токен доступа ВК для бота
access_token: "vk1.a.bCYPtQuE2YA3sP-9amAP1Emkfbkxf9mzu013"

# Токен доступа сообщества Логов (нужен токен пользователя админа)
logs_admin_token: "vk1.a.7bOOkw"

#=====================================#
#          RCON Настройки             #
#=====================================#

# Параметры ркон серверов
servers:
  grief:
    rcon_host: "149.144.69.76"
    rcon_port: 19133
    rcon_password: "DyZmXu"
  cookie:
    rcon_host: "178.236.243.51"
    rcon_port: 19132
    rcon_password: "hk1FLLtk"
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
    - "cookie"
  Console:
    - "linux"
  SigmaAdmin:
    - "cookie"

# Список всех существующих рангов
valid_ranks:
  valid:
  - "Нету"
  - "Console"
  - "GlConsole"
  - "Developer"
  - "Administrator"
  - "SeniorAdmin"
  - "Helper"
  - "Moderator"
  - "Support"
  - "Manager"
  - "Deputy"
  - "SuperAdmin"
  - "SigmaAdmin"
# Списки разрешённых команд для каждого ранга
permissions:
  Console:
    - "list"
    - "say"
  GlConsole:
    - "say"
    - "list"
  Developer:
    - "hban"
    - "hmute"
  Helper:
    - "fmute"
    - "fban"
    - "say"
    - "usrinfo"
    - "fkick"
    - "msg"
    - "list"
    - "fpardon"
    - "time"
  Moderator:
    - "fmute"
    - "fban"
    - "usrinfo"
    - "say"
    - "fkick"
    - "msg"
    - "list"
    - "tp"
    - "givemoney"
    - "fpardon"
    - "time"
  Administrator:
    - "fmute"
    - "fban"
    - "usrinfo"
    - "say"
    - "fkick"
    - "msg"
    - "list"
    - "tp"
    - "givemoney"
    - "groups"
    - "fpardon"
    - "time"
  SeniorAdmin:
    - "fmute"
    - "fban"
    - "usrinfo"
    - "say"
    - "fkick"
    - "msg"
    - "list"
    - "tp"
    - "givemoney"
    - "groups"
    - "fpardon"
    - "kill"
    - "time"
  Deputy:
    - "fmute"
    - "fban"
    - "usrinfo"
    - "say"
    - "fkick"
    - "msg"
    - "list"
    - "tp"
    - "givemoney"
    - "groups"
    - "kill"
    - "fpardon"
    - "gamemode"
    - "give"
    - "time"
  Support:
    - "fmute"
    - "fban"
    - "usrinfo"
    - "say"
    - "fkick"
    - "msg"
    - "list"
    - "tp"
    - "givemoney"
    - "groups"
    - "kill"
    - "fpardon"
    - "gamemode"
    - "give"
    - "time"
  Manager:
    - "fmute"
    - "fban"
    - "usrinfo"
    - "say"
    - "fkick"
    - "msg"
    - "list"
    - "tp"
    - "givemoney"
    - "groups"
    - "kill"
    - "fpardon"
    - "gamemode"
    - "give"
    - "time"
  SigmaAdmin:
    - "*"
  SuperAdmin:
    - "*"  # "*" Всё Команды
#=====================================#
#          ВЫСШИЕ АДМИНЫ              #
#=====================================#
 # Админы вк (доступ к командам "помощь админ" и до 5акков на один вк айди)
admins:
  - 000000000  # VK ID первого админа
  - 000000000  # VK ID второго админа
  - 789886989  # VK ID третьего админа
  - 662999836  # и тд

#=====================================#
#          НАСТРОЙКИ MySQL            #
#=====================================#

mysql_host: 'sql7.se.com' #Хост
mysql_user: 'sq759' #Пользователь 
mysql_pass: '8cTY92' #Пароль
mysql_survival_db: 'survival_data' #база данных для данных сервера
mysql_bot_db: 'bot_data' # бд для данных бота


#=====================================#
#           РАЗРЕШЕНИЯ                #
#=====================================#

# какие ранги имеют доступ к командам из "помощь админ"
allowed_ranks:
  report:
    - "Administrator"
    - "SigmaAdmin"
    - "SuperAdmin"
  vk_info:
    - "SuperAdmin"
    - "SigmaAdmin"
    - "Support"
  user_list:
    - "SuperAdmin"
    - "SigmaAdmin"
  admin_help:
    - "SigmaAdmin"
    - "SuperAdmin"
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
